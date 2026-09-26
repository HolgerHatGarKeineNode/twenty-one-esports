<?php

use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\User;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\SignedEvent;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use WebSocket\Client;
use WebSocket\Message\Text;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Rank badges on the Nostr profile, and share posts (P11)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (never a public one), with a throwaway
| key behind the stubbed window.nostr:
|
| - "Show on my Nostr profile": the page reads the player's existing 10008
|   from the relay, the confirmation says the other badge stays, the signed
|   list on the relay holds the old entry and the new pair.
| - "Post on Nostr": the signed kind 1 reaches the relay with the card URL.
| - Every page with the new UI (badges tab, own player page, won tournament,
|   finished rated game) at 375 and 1440 px: no horizontal overflow, a clean
|   console, no response of 400 or more.
|
| SHARE_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const SHARE_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

/** Every resource and the document itself answered below 400. */
const SHARE_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const SHARE_NO_OVERFLOW = '() => document.documentElement.scrollWidth <= document.documentElement.clientWidth';

/** Every image on the page loaded (a card or badge that failed to render has naturalWidth 0). */
const SHARE_IMAGES_LOADED = '() => [...document.images].filter((i) => i.loading !== "lazy" || i.getBoundingClientRect().top < innerHeight).every((i) => i.complete && i.naturalWidth > 0)';

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Storage::fake('local');
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->port]);

    for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $this->port); $i++) {
        usleep(100_000);
    }

    $this->relayUrl = 'ws://127.0.0.1:'.$this->port;
    // Profile relays (the page reads and publishes) and league relays (the server publishes): the local relay only.
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl]]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function sharePage(User $user, string $to, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(SHARE_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);

    return $page;
}

function shareShot(Page $page, string $name): void
{
    $dir = getenv('SHARE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Send one signed event to the relay and wait for its OK. */
function relaySend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * What the relay holds for a filter, read with nak (stdin closed: without it
 * `nak req` answers nothing).
 *
 * @return list<array<string, mixed>>
 */
function relayQuery(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

test('badges go on the Nostr profile without losing the old list, a share post reaches the relay, every page is clean at 375 and 1440', function () {
    $user = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    $signer = TestSigner::forBrowser($user);
    $user->refresh();
    $season = openSeason(['slug' => 'pre-season']);
    $moments = shareMoments($user, $season);
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $user->id, 'black_id' => $moments['opponent']->id]);

    // The player's existing profile badges, written by another client, on the relay only.
    $existing = $signer->sign(10008, [['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 3600);
    relaySend($this->relayUrl, $existing);

    $pages = [
        'badges' => route('settings.badges', absolute: false),
        'player' => route('players.show', $user->npub, false),
        'tournament' => route('tournaments.show', $moments['tournament'], false),
        'game' => route('games.show', $game, false),
    ];

    foreach ([375, 1440] as $width) {
        foreach ($pages as $name => $to) {
            $page = sharePage($user, $to, $width);
            BrowserWait::until($page, SHARE_IMAGES_LOADED, 20_000);
            $metrics = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
            fwrite(STDERR, "\n[share] {$name} {$width}px scrollWidth/clientWidth: ".json_encode($metrics)."\n");
            shareShot($page, "share-{$name}-{$width}");

            expect([$name, $width, $page->evaluate(SHARE_NO_OVERFLOW)])->toBe([$name, $width, true])
                ->and([$name, $width, $page->evaluate('() => window.__errors')])->toBe([$name, $width, []])
                ->and([$name, $width, $page->evaluate(SHARE_BAD_RESPONSES)])->toBe([$name, $width, []]);
        }
    }

    // Show on my Nostr profile: read, confirm (the old badge stays), sign.
    $page = sharePage($user, $pages['badges'], 1440);
    $page->locator('[data-test=badge-show]')->click();
    BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display !== "none"', 15_000);
    expect($page->evaluate('() => document.querySelector("[data-test=badge-kept]").textContent.trim()'))->toBe('Your 1 other badge stays on your profile.')
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-relays]").textContent.trim()'))->toBe('1 of 1 of your relays answered.');
    shareShot($page, 'share-badge-confirm-1440');

    $page->locator('[data-test=badge-confirm-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=badge-listed]") !== null', 15_000);

    $badge = $moments['versions'][1]->badge;
    $lists = relayQuery($this->relayUrl, '-k 10008 -a '.$user->pubkey);

    expect($lists)->toHaveCount(1)
        ->and($lists[0]['tags'])->toBe([...$existing['tags'], ['a', $badge->address()], ['e', $badge->awardEvent->event_id], ['alt', 'Profile badges']])
        ->and(SignedEvent::fromInput($lists[0])?->hasValidSignature())->toBeTrue()
        ->and(NostrEvent::query()->where('kind', 10008)->where('event_id', $lists[0]['id'])->exists())->toBeTrue();

    // Post on Nostr: the block share.
    $page->locator('[data-test=share-moment][data-type=block] [data-test=share-post]')->first()->click();
    BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=share-moment][data-type=block] [data-test=share-done]")).display !== "none"', 15_000);
    shareShot($page, 'share-posted-1440');

    $notes = relayQuery($this->relayUrl, '-k 1 -a '.$user->pubkey);

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['content'])->toStartWith('Mined block 2 on the TWENTY ONE Esports season chain')
        ->and($notes[0]['content'])->toContain('/cards/en/block/'.$moments['block']->id.'/'.$user->npub.'-wide.png?v=')
        ->and($notes[0]['tags'][0][0])->toBe('imeta')
        ->and(SignedEvent::fromInput($notes[0])?->hasValidSignature())->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(SHARE_BAD_RESPONSES))->toBe([]);
});

test('the share collectors see a thrown error and a failed card (positive control)', function () {
    $user = User::factory()->create();
    TestSigner::forBrowser($user);

    $page = sharePage($user->refresh(), route('settings.badges', absolute: false), 1440);
    $page->evaluate('() => { const img = new Image(); img.src = "/cards/en/rank-up/999999-wide.png"; document.body.append(img); setTimeout(() => { throw new Error("positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("/cards/en/rank-up/999999"))', 5_000);

    expect(implode("\n", $page->evaluate(SHARE_BAD_RESPONSES)))->toMatch('#^404 http://\S+/cards/en/rank-up/999999-wide\.png$#m');
});

/**
 * A throwaway MiniRelay (tests/Support/MiniRelay.php: no signature check, events served in seed order).
 *
 * @param  list<array<string, mixed>>  $seed
 * @return array{0: InvokedProcess, 1: string}
 */
function shareMiniRelay(array $seed): array
{
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $file = storage_path('framework/testing/share-seed-'.$port.'.json');
    File::ensureDirectoryExists(dirname($file));
    File::put($file, (string) json_encode($seed));
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $file]);

    for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }

    return [$relay, 'ws://127.0.0.1:'.$port];
}

test('a forged copy served first does not hide the real list, a write relay that is down refuses, and no relay taking the list shows no success', function () {
    $season = openSeason(['slug' => 'pre-season']);
    [$anna, $bert] = [User::factory()->create(['name' => 'anna']), User::factory()->create(['name' => 'bert'])];
    $annaKey = TestSigner::forBrowser($anna);
    $bertKey = TestSigner::forBrowser($bert);
    shareMoments($anna->refresh(), $season);
    // Bert needs a rank badge only (blocks are unique per season and height, Anna has them).
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$bert->id, 'user_id' => $bert->id, 'rating' => 1040, 'results' => 6]);
    app(RankBadges::class)->sync($bert->refresh(), 'chess', 'blitz');

    // Anna's real list, and a forged copy with the same id and a junk signature, served FIRST.
    $real = $annaKey->sign(10008, [['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 3600);
    $forged = [...$real, 'sig' => str_repeat('0', 128)];
    // Bert's relay list names one write relay, and it is down.
    $closed = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relayList = $bertKey->sign(10002, [['r', 'ws://127.0.0.1:'.$closed]]);

    [$relay, $url] = shareMiniRelay([$forged, $real, $relayList]);
    config(['esports.profile_relays' => [$url], 'esports.relays' => []]);

    try {
        // Bert: the write relay never answers, so nothing is read and the league refuses.
        $page = sharePage($bert, route('settings.badges', absolute: false), 1440);
        $page->locator('[data-test=badge-show]')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=rank-badges] [role=alert]")].some((e) => e.offsetParent !== null && e.textContent.includes("could not be read"))', 15_000);

        expect($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display'))->toBe('none')
            ->and(NostrEvent::query()->where('kind', 10008)->where('pubkey', $bert->pubkey)->exists())->toBeFalse();

        // Anna: the forged copy arrives first and is dropped; the real list is found.
        $page = sharePage($anna, route('settings.badges', absolute: false), 1440);
        $page->locator('[data-test=badge-show]')->click();
        BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display !== "none"', 15_000);

        expect($page->evaluate('() => document.querySelector("[data-test=badge-kept]").textContent.trim()'))->toBe('Your 1 other badge stays on your profile.');
    } finally {
        // The relay goes away before Anna signs: no relay takes her list.
        $relay->stop(1);
    }

    $page->locator('[data-test=badge-confirm-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=badge-warning]")?.offsetParent != null', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-test=badge-warning]").textContent.trim()'))->toBe('None of your relays took the new list. Nothing was changed.')
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-added]")?.offsetParent ?? null'))->toBeNull()
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-listed]")'))->toBeNull()
        // Only the real list the read found is archived; the new one never reached the league.
        ->and(NostrEvent::query()->where('kind', 10008)->where('pubkey', $anna->pubkey)->pluck('event_id')->all())->toBe([$real['id']])
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});
