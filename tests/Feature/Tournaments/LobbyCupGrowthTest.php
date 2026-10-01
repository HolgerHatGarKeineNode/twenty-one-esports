<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentDraws;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Growing lobby cups (plan "AoE2 und Trackmania", P10)
|--------------------------------------------------------------------------
|
| An Age of Empires II casual cup opens small like every cup and grows with
| its sign-ups: 4, 8, then a full lobby of 8 at a time up to 40, whenever
| only one place is left and until one hour before sign-up closes. The cups
| opened with all 40 places shrink once to the step above their sign-ups.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->tip = 900000;
    $hash = hash('sha256', 'block 900001');
    Http::fake(fn ($request) => match (true) {
        str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $this->tip),
        str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
        str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
        default => Http::response('', 404),
    });
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['age-of-empires-2']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** The open Age of Empires II cup of a region. */
function lobbyCup(string $region = 'eu'): Tournament
{
    return Tournament::query()->where('cup_open_series', "age-of-empires-2-{$region}")->firstOrFail();
}

/** The cup's 31923 versions. */
function lobbyCupVersions(Tournament $cup): int
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->count();
}

/** The content of the cup's latest 31923. */
function lobbyCupContent(Tournament $cup): string
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->latest('id')->firstOrFail()->payload()['content'];
}

/** `$n` players sign up with no growth in between (the league's tick comes later). */
function lobbyCupSignups(Tournament $cup, int $n): void
{
    foreach (range(1, $n) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
    }
}

function runLobbyCupShrinkMigration(): void
{
    (require database_path('migrations/2026_10_01_180000_shrink_open_lobby_cups_to_their_signups.php'))->up();
}

test('a new Age of Empires II cup opens as one lobby match with 4 places, and its 31923 says it grows to 40', function () {
    cupTick();
    $cup = lobbyCup();

    expect($cup->format)->toBe(TournamentFormat::FreeForAll)
        ->and($cup->capacity)->toBe(4)
        ->and(lobbyCup('us')->capacity)->toBe(4)
        ->and(lobbyCupContent($cup))->toContain('Places: 4 for now; the league adds places as they fill, up to 40, until 60 minutes before sign-up closes.');
});

test('a lobby cup grows 4 to 8 to 16 to 24 as sign-ups come in, one 31923 version per growth', function () {
    cupTick();
    $cup = lobbyCup();
    $versions = lobbyCupVersions($cup);

    // The clock ticks every minute; versions of one event are signed at least a second apart.
    lobbyCupSignups($cup, 2);
    $this->travel(1)->minutes();
    cupTick();
    expect($cup->refresh()->capacity)->toBe(4);

    lobbyCupSignups($cup, 1);
    $this->travel(1)->minutes();
    cupTick();
    expect($cup->refresh()->capacity)->toBe(8)
        ->and(lobbyCupContent($cup))->toContain('Places: 8 for now;');

    lobbyCupSignups($cup, 4);
    $this->travel(1)->minutes();
    cupTick();
    expect($cup->refresh()->capacity)->toBe(16);

    lobbyCupSignups($cup, 8);
    $this->travel(1)->minutes();
    cupTick();

    expect($cup->refresh()->capacity)->toBe(24)
        ->and($cup->status)->toBe(TournamentStatus::Signup)
        ->and(lobbyCupVersions($cup))->toBe($versions + 3)
        ->and(lobbyCupContent($cup))->toContain('Places: 24 for now; the league adds places as they fill, up to 40, until 60 minutes before sign-up closes.');
});

test('a lobby cup grows a full lobby at a time and stops at 40', function () {
    cupTick();
    $cup = lobbyCup();
    $cups = app(CasualCups::class);

    $cup->update(['capacity' => 24]);
    expect($cups->grow($cup->refresh(), 23))->toBeTrue()
        ->and($cup->refresh()->capacity)->toBe(32)
        ->and($cups->grow($cup->refresh(), 31))->toBeTrue()
        ->and($cup->refresh()->capacity)->toBe(40)
        ->and(lobbyCupContent($cup))->toContain('Places: 40.')
        ->and($cups->grow($cup->refresh(), 39))->toBeFalse()
        ->and($cups->grow($cup->refresh(), 40))->toBeFalse()
        ->and($cup->refresh()->capacity)->toBe(40)
        ->and(Lobbies::cupSizes('age-of-empires-2'))->toBe([4, 8, 16, 24, 32, 40]);
});

test('from one hour before the close a lobby cup no longer grows; full then, it starts at once and plays one lobby', function () {
    cupTick();
    $cup = lobbyCup();
    lobbyCupSignups($cup, 3);
    $versions = lobbyCupVersions($cup);

    $this->travelTo($cup->signup_closes_at->subMinutes(60));
    cupTick();

    expect($cup->refresh()->capacity)->toBe(4)
        ->and(lobbyCupVersions($cup))->toBe($versions);

    lobbyCupSignups($cup, 1);
    cupTick();

    expect($cup->refresh()->capacity)->toBe(4)
        ->and($cup->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->signup_closes_at->lessThanOrEqualTo(now()))->toBeTrue();

    // Four entries are above the lobby minimum of 3: one lobby of 4.
    $this->tip = 900006;
    expect(app(TournamentDraws::class)->resolve($cup->refresh()))->toBeTrue();
    cupTick();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(TournamentMatch::query()->where('tournament_id', $cup->id)->with('slots')->get()->map(fn (TournamentMatch $match): int => $match->slots->count())->all())->toBe([4]);
});

test('the migration shrinks the open lobby cups to the step above their sign-ups, republishes them once, and leaves the rest', function () {
    config(['esports.casual_cups.enabled' => ['age-of-empires-2', 'chess']]);
    cupTick();
    // The lobby cups as they were opened before: all 40 places.
    $eu = lobbyCup('eu');
    $us = lobbyCup('us');
    $eu->update(['capacity' => 40]);
    $us->update(['capacity' => 40]);
    lobbyCupSignups($eu, 1);
    lobbyCupSignups($us, 9);
    // An organizer's lobby tournament keeps its 40; a chess cup is not a lobby cup.
    $organized = openTournament(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray(Lobbies::options('age-of-empires-2'), GameProfile::for('age-of-empires-2', '1v1'))->toArray(), 'capacity' => 40]);
    lobbyCupSignups($organized, 1);
    $chess = Tournament::query()->where('cup_open_series', 'chess-eu')->firstOrFail();
    $untouched = [$organized->refresh()->event_id, $chess->refresh()->event_id, $chess->capacity];
    $versions = [lobbyCupVersions($eu), lobbyCupVersions($us)];

    runLobbyCupShrinkMigration();

    expect($eu->refresh()->capacity)->toBe(4)
        ->and($us->refresh()->capacity)->toBe(16)
        ->and([lobbyCupVersions($eu), lobbyCupVersions($us)])->toBe([$versions[0] + 1, $versions[1] + 1])
        ->and(lobbyCupContent($eu))->toContain('Places: 4 for now; the league adds places as they fill, up to 40,')
        ->and(lobbyCupContent($us))->toContain('Places: 16 for now;')
        ->and($organized->refresh()->capacity)->toBe(40)
        ->and([$organized->event_id, $chess->refresh()->event_id, $chess->capacity])->toBe($untouched)
        // Never below the sign-ups: the step above them, empty or full.
        ->and(Lobbies::cupSizeFor('age-of-empires-2', 0))->toBe(4)
        ->and(Lobbies::cupSizeFor('age-of-empires-2', 3))->toBe(4)
        ->and(Lobbies::cupSizeFor('age-of-empires-2', 8))->toBe(16)
        ->and(Lobbies::cupSizeFor('age-of-empires-2', 39))->toBe(40)
        ->and(Lobbies::cupSizeFor('age-of-empires-2', 40))->toBe(40);

    // Twice is once.
    $events = NostrEvent::query()->count();
    runLobbyCupShrinkMigration();

    expect([$eu->refresh()->capacity, $us->refresh()->capacity])->toBe([4, 16])
        ->and(NostrEvent::query()->count())->toBe($events);
});

test('a cup of another game keeps its own sizes: 4, 8, 16 and no lobby steps', function () {
    config(['esports.casual_cups.enabled' => ['chess']]);
    cupTick();
    $cup = openCup();
    $cups = app(CasualCups::class);

    expect($cup->capacity)->toBe(4)
        ->and(CasualCups::sizesOf($cup))->toBe([4, 8, 16]);

    $cup->update(['capacity' => 16]);

    expect($cups->grow($cup->refresh(), 15))->toBeFalse()
        ->and($cup->refresh()->capacity)->toBe(16);
});

test('the rules say an Age of Empires II cup opens with 4 places and grows a full lobby at a time up to 40, in English and German', function () {
    $this->get(route('rules'))->assertOk()
        ->assertSee('cups are one lobby match: they open with 4 places and grow the same way, a full lobby of 8 at a time above 8, up to 40.')
        ->assertDontSee('places from the start');

    $this->get(route('rules', ['lang' => 'de']))->assertOk()
        ->assertSee('Sie starten mit 4 Plätzen und wachsen genauso, über 8 jeweils um eine volle Lobby mit 8 Plätzen, bis 40.');
});
