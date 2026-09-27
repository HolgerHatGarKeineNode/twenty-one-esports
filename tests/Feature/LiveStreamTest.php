<?php

use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The live stream around the site (P20): LiveStatus reads the playlist's
 * age (fresh, stale, missing; fail closed), the header badge and the
 * floating player exist only on air, /live renders on and off air for a
 * guest and a player, and the floating player is never on /live or the TV.
 */

beforeEach(function () {
    $this->hls = sys_get_temp_dir().'/esports-live-test-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->hls);
    config([
        'twentyone.stream.hls_dir' => $this->hls,
        'twentyone.stream.public_url' => 'https://esports.example/live/stream.m3u8',
        'esports.league.nsec' => (new TestSigner)->secret,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->hls);
});

function onAir(?array $announced = null): void
{
    file_put_contents(test()->hls.'/stream.m3u8', "#EXTM3U\n");
    touch(test()->hls.'/stream.m3u8', time() - 2);

    if ($announced !== null) {
        Cache::put(LiveStatus::ANNOUNCED_KEY, $announced, 60);
    }
}

test('the stream is live while its playlist moved within three segment lengths', function () {
    onAir();

    expect(LiveStatus::freshSeconds())->toBe(18)
        ->and(LiveStatus::read()->live)->toBeTrue()
        ->and(LiveStatus::read()->viewers)->toBeNull();

    // The boundary: 17 s old is on air, 18 s is off.
    touch($this->hls.'/stream.m3u8', time() - 17);
    expect(LiveStatus::read()->live)->toBeTrue();
    touch($this->hls.'/stream.m3u8', time() - 18);
    expect(LiveStatus::read()->live)->toBeFalse();
});

test('a stale, missing or unreadable playlist is off air', function () {
    onAir();
    touch($this->hls.'/stream.m3u8', time() - 600);
    expect(LiveStatus::read()->live)->toBeFalse();

    File::delete($this->hls.'/stream.m3u8');
    expect(LiveStatus::read()->live)->toBeFalse();

    config(['twentyone.stream.hls_dir' => $this->hls.'/gone']);
    expect(LiveStatus::read()->live)->toBeFalse();
});

test('the playlist file is named after the public URL', function () {
    config(['twentyone.stream.public_url' => 'https://esports.example/live/other.m3u8']);
    file_put_contents($this->hls.'/stream.m3u8', "#EXTM3U\n");
    expect(LiveStatus::read()->live)->toBeFalse();

    file_put_contents($this->hls.'/other.m3u8', "#EXTM3U\n");
    expect(LiveStatus::read()->live)->toBeTrue();
});

test('viewers and title come from what the stream announced, and nothing else', function () {
    onAir(['viewers' => 12, 'title' => 'Live now: Alice vs Bob', 'summary' => 'Chess']);
    expect(LiveStatus::read())->viewers->toBe(12)->title->toBe('Live now: Alice vs Bob');

    Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => '12', 'title' => ['x']], 60);
    expect(LiveStatus::read())->viewers->toBeNull()->title->toBeNull()->live->toBeTrue();

    // Off air, an old announcement says nothing.
    Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 12], 60);
    touch($this->hls.'/stream.m3u8', time() - 600);
    expect(LiveStatus::read())->live->toBeFalse()->viewers->toBeNull();
});

test('the status is cached for a few seconds and read once per request', function () {
    onAir();
    expect(LiveStatus::current()->live)->toBeTrue();

    File::delete($this->hls.'/stream.m3u8');
    request()->attributes->remove(LiveStatus::CACHE_KEY);
    expect(LiveStatus::current()->live)->toBeTrue();

    $this->travel(LiveStatus::CACHE_SECONDS + 1)->seconds();
    request()->attributes->remove(LiveStatus::CACHE_KEY);
    expect(LiveStatus::current()->live)->toBeFalse();
});

test('the header badge and the floating player exist only on air', function () {
    $this->get(route('home'))->assertOk()
        ->assertDontSee('data-test="live-badge"', false)
        ->assertDontSee('data-test="live-player"', false)
        ->assertDontSee('data-test="mobile-live-on-air"', false)
        // The page itself stays reachable off air: "Live" in row 1 from lg, More on phones.
        ->assertSee('data-test="mobile-live"', false)
        ->assertSee('data-test="nav-live"', false);

    Cache::flush();
    onAir(['viewers' => 7]);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="live-badge"', false)
        ->assertDontSee('data-test="nav-live"', false)
        ->assertSee('data-test="mobile-live-on-air"', false)
        ->assertSee('Live stream on air, 7 watching')
        ->assertSee('data-test="live-player"', false)
        ->assertSee('x-persist="live-player"', false)
        ->assertSee('esports.example', false);
});

test('/live renders on and off air, for a guest and a player', function (bool $live, bool $player) {
    if ($live) {
        onAir(['viewers' => 3, 'title' => 'Live now: Ana vs Ben']);
    }

    $game = ChessGame::factory()->create();

    if ($player) {
        $this->actingAs(User::factory()->create());
    }

    $response = $this->get(route('live'))->assertOk()
        ->assertSee('Live stream')
        ->assertSee('data-live="'.($live ? '1' : '0').'"', false)
        ->assertSee(route('games.show', $game))
        // A QR code, never a Lightning address as text.
        ->assertSee('data-test="live-zap"', false)
        ->assertDontSee((string) config('twentyone.profile.lud16'));

    if ($live) {
        $response->assertSee('data-test="live-stage-video"', false)->assertSee('data-live-stage', false)
            ->assertSee('Live now: Ana vs Ben')->assertSee('data-test="live-viewers"', false)
            ->assertDontSee('data-test="live-offline"', false);
    } else {
        $response->assertSee('data-test="live-offline"', false)->assertSee('The stream is off air right now.')
            ->assertDontSee('data-test="live-stage-video"', false);
    }

    // The page's own player only: no floating one, whose markup would carry the persist key.
    $response->assertDontSee('x-persist="live-player"', false);
})->with(['on air' => true, 'off air' => false])->with(['guest' => false, 'player' => true]);

test('/live off air names the next tournament when one is scheduled', function () {
    $next = openTournament(['name' => 'Autumn Blitz Cup', 'starts_at' => now()->addDays(3)]);

    $this->get(route('live'))->assertOk()
        ->assertSee('data-test="live-offline-next"', false)
        ->assertSee('Autumn Blitz Cup')
        ->assertSee(route('tournaments.show', $next));
});

test('/live lists running tournaments with their TV view', function () {
    $running = runningChess(TournamentFormat::SingleElimination, 4);

    $this->get(route('live'))->assertOk()
        ->assertSee('data-test="live-tournaments"', false)
        ->assertSee(route('tournaments.tv', $running));
});

test('the floating player is never on the TV view', function () {
    $running = runningChess(TournamentFormat::SingleElimination, 4);
    $running->forceFill(['published_at' => now()->subDay()])->save();
    onAir();

    $this->get(route('tournaments.tv', $running))->assertOk()
        ->assertDontSee('data-test="live-player"', false)
        ->assertDontSee('data-test="live-badge"', false);
});

test('the /live page survives a Livewire roundtrip on and off air', function () {
    Livewire::test('pages::live')->call('$refresh')->assertOk()->assertSee('The stream is off air right now.');

    Cache::flush();
    onAir();
    Livewire::test('pages::live')->call('$refresh')->assertOk()->assertSee('data-live-stage', false);
});
