<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Casual cups per region (user, 2026-09-28: separate EU and US cups)
|--------------------------------------------------------------------------
|
| Every game runs an EU and a US cup series. A cup starts at its region's
| slot (Saturday 20:00 on the region's own clock, across daylight saving:
| Europe and the US switch on different dates) with at least
| min_signup_hours of sign-up; its evening and its extension stay on that
| clock. The data migration moves the cups opened before into the EU series
| and opens the US ones.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A region's slot as "<weekday> <local date and time>" and the UTC instant. */
function regionSlot(string $region, string $notBeforeUtc): string
{
    $slot = CasualCups::nextSlot($region, CarbonImmutable::parse($notBeforeUtc, 'UTC'));

    return $slot->setTimezone(CasualCups::regions()[$region]['timezone'])->format('D Y-m-d H:i T').' = '.$slot->utc()->format('Y-m-d H:i').' UTC';
}

/** The published versions of a cup's calendar event. */
function regionVersions(Tournament $cup): int
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->count();
}

/**
 * A cup as the league opened it before the regions: the game as its
 * series, "<Game> Casual Cup #n", published; `$players` signed up solo.
 *
 * @param  array<string, mixed>  $attributes
 */
function legacyCup(string $game, int $number, CarbonImmutable $startsAt, int $players, array $attributes = []): Tournament
{
    $setup = CasualCups::setup($game);
    $profile = GameProfile::for($game, $setup['mode']);
    $cup = Tournament::factory()->create([
        'name' => "{$setup['name']} Casual Cup #{$number}", 'game' => $game, 'mode' => $setup['mode'],
        'format' => TournamentFormat::DoubleElimination, 'capacity' => 4, 'starts_at' => $startsAt, 'signup_closes_at' => null,
        'options' => FormatOptions::fromArray(['bestOf' => $setup['best_of'], 'finalBestOf' => $setup['final_best_of'], 'grandFinal' => 'single'], $profile)->toArray(),
        'time_window' => CasualCups::maxDays() * ($profile->isDaily() ? 1 : 1440), 'on_site' => false,
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Draft, 'created_by_id' => null,
        'cup_series' => $game, 'cup_number' => $number, 'cup_open_series' => $game,
    ]);
    $cup = app(TournamentPublisher::class)->openSignup($cup, $startsAt);

    for ($index = 0; $index < $players; $index++) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
    }

    if ($attributes !== []) {
        $cup->forceFill($attributes)->save();
    }

    return $cup->refresh();
}

function splitMigration(): void
{
    (require database_path('migrations/2026_09_28_163510_split_casual_cups_into_regions.php'))->up();
}

/* ---------- Slots --------------------------------------------------------------------------------------------- */

test('a region\'s slot stays Saturday 20:00 on its own clock across both daylight saving changes', function (string $region, string $notBefore, string $slot) {
    expect(regionSlot($region, $notBefore))->toBe($slot);
})->with([
    // Autumn: Europe goes back on 25 October, the US on 1 November; in between the two are five hours apart, not six.
    'EU before its change' => ['eu', '2026-10-19 00:00', 'Sat 2026-10-24 20:00 CEST = 2026-10-24 18:00 UTC'],
    'EU after its change' => ['eu', '2026-10-25 00:00', 'Sat 2026-10-31 20:00 CET = 2026-10-31 19:00 UTC'],
    'US while Europe changed already' => ['us', '2026-10-25 01:00', 'Sat 2026-10-31 20:00 EDT = 2026-11-01 00:00 UTC'],
    'US after its change' => ['us', '2026-11-01 01:00', 'Sat 2026-11-07 20:00 EST = 2026-11-08 01:00 UTC'],
    // Spring: the US goes forward on 14 March 2027, Europe on 28 March.
    'US before its change' => ['us', '2027-03-08 00:00', 'Sat 2027-03-13 20:00 EST = 2027-03-14 01:00 UTC'],
    'US after its change' => ['us', '2027-03-15 00:00', 'Sat 2027-03-20 20:00 EDT = 2027-03-21 00:00 UTC'],
    'EU before its change (spring)' => ['eu', '2027-03-22 00:00', 'Sat 2027-03-27 20:00 CET = 2027-03-27 19:00 UTC'],
    'EU after its change (spring)' => ['eu', '2027-03-28 00:00', 'Sat 2027-04-03 20:00 CEST = 2027-04-03 18:00 UTC'],
    // A moment exactly on a slot is that slot; a second later the next week's.
    'EU on the slot' => ['eu', '2026-10-10 18:00:00', 'Sat 2026-10-10 20:00 CEST = 2026-10-10 18:00 UTC'],
    'EU a second after' => ['eu', '2026-10-10 18:00:01', 'Sat 2026-10-17 20:00 CEST = 2026-10-17 18:00 UTC'],
    // Saturday evening in New York is already Sunday in UTC.
    'US late Saturday UTC' => ['us', '2026-10-10 23:00', 'Sat 2026-10-10 20:00 EDT = 2026-10-11 00:00 UTC'],
]);

test('a cup opens at the first slot of its region that leaves at least min_signup_hours of sign-up', function () {
    // Thursday 19:00 UTC: 48 h later is Saturday 19:00 UTC, past the EU slot (18:00 UTC) but before the US one (00:00 UTC Sunday).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 19:00', 'UTC'));
    cupTick();

    $eu = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    $us = Tournament::query()->where('cup_open_series', 'chess-us')->sole();

    expect($eu->name)->toBe('Chess Casual Cup EU #1')
        ->and($us->name)->toBe('Chess Casual Cup US #1')
        ->and($eu->signup_closes_at->setTimezone('Europe/Berlin')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-17 20:00')
        ->and($us->signup_closes_at->setTimezone('America/New_York')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-10 20:00')
        ->and($eu->starts_at->equalTo($eu->signup_closes_at))->toBeTrue()
        ->and($us->starts_at->equalTo($us->signup_closes_at))->toBeTrue()
        ->and(now()->diffInHours($us->signup_closes_at))->toBeGreaterThanOrEqual(48.0);

    // A longer minimum pushes the US cup a week on as well.
    config(['esports.casual_cups.min_signup_hours' => 60]);

    expect(CasualCups::startFor('us', now())->setTimezone('America/New_York')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-17 20:00');
});

test('a game has one open cup per region: two open cups of a game are allowed, a second one in a region is not', function () {
    cupTick();
    cupTick();

    expect(Tournament::query()->whereNotNull('cup_open_series')->orderBy('cup_open_series')->pluck('cup_open_series')->all())->toBe(['chess-eu', 'chess-us'])
        ->and(app(CasualCups::class)->ensure('chess', 'us'))->toBeNull()
        ->and(app(CasualCups::class)->ensure('chess', 'mars'))->toBeNull()
        // The unique index is the guard a concurrent run meets.
        ->and(fn () => Tournament::factory()->create(['created_by_id' => null, 'cup_series' => 'chess-us', 'cup_number' => 2, 'cup_open_series' => 'chess-us']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the numbering runs per series: EU goes on to #2 while US stays at #1', function () {
    cupTick();
    Tournament::query()->where('cup_open_series', 'chess-eu')->sole()->forceFill(['status' => TournamentStatus::Finished])->save();
    cupTick();
    $this->travel(24)->hours();
    cupTick();

    expect(Tournament::query()->where('cup_series', 'chess-eu')->orderBy('cup_number')->pluck('name')->all())->toBe(['Chess Casual Cup EU #1', 'Chess Casual Cup EU #2'])
        ->and(Tournament::query()->where('cup_series', 'chess-us')->pluck('name')->all())->toBe(['Chess Casual Cup US #1']);
});

/* ---------- The evening and the extension on the region's clock ----------------------------------------------- */

test('a US cup with three players plays its evening at 20:00 New York the next day, and says so in New York time', function () {
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);
    $cup = app(CasualCups::class)->ensure('chess', 'us');
    cupSignups($cup, 3);

    $this->travelTo($cup->refresh()->signup_closes_at);
    cupTick();
    $cup->refresh();
    $player = User::query()->findOrFail($cup->signups()->firstOrFail()->members[0]);

    expect($cup->format)->toBe(TournamentFormat::RoundRobin)
        ->and($cup->starts_at->setTimezone('America/New_York')->format('D Y-m-d H:i'))->toBe('Sun 2026-10-11 20:00')
        ->and($player->timezone)->toBeNull()
        ->and($player->notifications()->get()->pluck('data.title')->last())->toBe('Chess Casual Cup US #1: live evening Sun 11 Oct, 20:00 EDT');
});

test('a US cup with a lone player is extended to the next US slot, across the US clock change', function () {
    // Opened Tuesday 27 October: its slot is Saturday 31 October, still daylight time in New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00', 'UTC'));
    $cup = app(CasualCups::class)->ensure('chess', 'us');
    cupSignups($cup, 1);

    expect($cup->refresh()->signup_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-01 00:00');

    $this->travelTo($cup->signup_closes_at);
    cupTick();
    $cup->refresh();

    // A week later on the New York clock: 20:00 EST is one hour later in UTC.
    expect($cup->cup_extended_at)->not->toBeNull()
        ->and($cup->signup_closes_at->setTimezone('America/New_York')->format('D Y-m-d H:i T'))->toBe('Sat 2026-11-07 20:00 EST')
        ->and($cup->signup_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-08 01:00')
        ->and($cup->starts_at->equalTo($cup->signup_closes_at))->toBeTrue();
});

test('the auto slot of a US cup is 20:00 in New York, of an EU cup 20:00 in Berlin', function () {
    $end = CarbonImmutable::parse('2026-10-08 12:00', 'UTC');
    $us = Tournament::factory()->make(['cup_series' => 'chess-us']);
    $eu = Tournament::factory()->make(['cup_series' => 'chess-eu']);

    expect(CasualCups::autoSlot($end, CasualCups::timezoneOf($us))->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-10-07 20:00')
        ->and(CasualCups::autoSlot($end, CasualCups::timezoneOf($eu))->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-07 20:00')
        ->and(CasualCups::regionLabel($us))->toBe('US')
        ->and(CasualCups::regionOf(Tournament::factory()->make(['cup_series' => 'chess'])))->toBeNull();
});

/* ---------- The data migration -------------------------------------------------------------------------------- */

test('the migration moves the old cups into the EU series, re-slots them, tells their players once and opens the US cups', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    // Monday 5 October 10:00 UTC. The live cups started at 00:25 and 03:37 Berlin time.
    $chess = legacyCup('chess', 1, CarbonImmutable::parse('2026-10-07 22:25', 'UTC'), 2);
    $rlDone = legacyCup('rocket-league', 1, CarbonImmutable::parse('2026-09-20 18:00', 'UTC'), 0,
        ['status' => TournamentStatus::Finished, 'cup_open_series' => null, 'cup_ended_at' => now()->subDays(2)]);
    // Players signed up for Tuesday 13 October 03:37 Berlin: never earlier, so not the 10 October slot.
    $rl = legacyCup('rocket-league', 2, CarbonImmutable::parse('2026-10-13 01:37', 'UTC'), 1);
    // Nobody signed up yet: it may move earlier.
    $fc = legacyCup('ea-sports-fc-26', 1, CarbonImmutable::parse('2026-10-13 01:37', 'UTC'), 0);
    $versions = array_map(fn (Tournament $cup): int => regionVersions($cup), [$chess, $rlDone, $rl, $fc]);
    $players = User::query()->whereKey([...$chess->signups()->get()->flatMap->members, ...$rl->signups()->get()->flatMap->members])->get();
    $noticesBefore = $players->map(fn (User $player): int => $player->notifications()->count())->all();

    splitMigration();

    [$chess, $rlDone, $rl, $fc] = array_map(fn (Tournament $cup): Tournament => $cup->refresh(), [$chess, $rlDone, $rl, $fc]);

    expect([$chess->name, $rlDone->name, $rl->name, $fc->name])->toBe(['Chess Casual Cup EU #1', 'Rocket League Casual Cup #1', 'Rocket League Casual Cup EU #2', 'EA FC 26 Casual Cup EU #1'])
        ->and([$chess->cup_series, $rlDone->cup_series, $rl->cup_series, $fc->cup_series])->toBe(['chess-eu', 'rocket-league-eu', 'rocket-league-eu', 'ea-sports-fc-26-eu'])
        ->and([$chess->cup_open_series, $rlDone->cup_open_series, $rl->cup_open_series, $fc->cup_open_series])->toBe(['chess-eu', null, 'rocket-league-eu', 'ea-sports-fc-26-eu'])
        // The next EU slot at least 48 h away is Saturday 10 October 20:00 Berlin, for the Rocket League cup the one after its old start.
        ->and($chess->starts_at->setTimezone('Europe/Berlin')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-10 20:00')
        ->and($rl->starts_at->setTimezone('Europe/Berlin')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-17 20:00')
        ->and($fc->starts_at->setTimezone('Europe/Berlin')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-10 20:00')
        ->and($chess->signup_closes_at->equalTo($chess->starts_at) && $rl->signup_closes_at->equalTo($rl->starts_at))->toBeTrue()
        ->and($chess->signups()->active()->count() + $rl->signups()->active()->count())->toBe(3)
        // One new version of each open cup's 31923 with the new name and start; the ended cup keeps its history.
        ->and(array_map(fn (Tournament $cup): int => regionVersions($cup), [$chess, $rlDone, $rl, $fc]))->toBe([$versions[0] + 1, $versions[1], $versions[2] + 1, $versions[3] + 1])
        ->and(NostrEvent::query()->findOrFail($chess->event_id)->payload()['tags'][1])->toBe(['title', 'Chess Casual Cup EU #1'])
        ->and((int) collect(NostrEvent::query()->findOrFail($chess->event_id)->payload()['tags'])->firstWhere(0, 'start')[1])->toBe($chess->starts_at->getTimestamp())
        // Each signed-up player hears the new start once.
        ->and($players->map(fn (User $player): int => $player->notifications()->count())->all())->toBe(array_map(fn (int $count): int => $count + 1, $noticesBefore))
        ->and($players->first()->notifications()->latest('id')->first()->data['title'])->toBe('Chess Casual Cup EU #1 now starts Sat 10 Oct, 20:00 CEST');

    // Every enabled game opened its US cup right away, published like any new cup, at the next US slot.
    $us = Tournament::query()->whereNotNull('cup_open_series')->where('cup_open_series', 'like', '%-us')->orderBy('cup_open_series')->get();

    expect($us->pluck('name')->all())->toBe(['Chess Casual Cup US #1', 'EA FC 26 Casual Cup US #1', 'Rocket League Casual Cup US #1'])
        ->and($us->every(fn (Tournament $cup): bool => $cup->event_id !== null && $cup->status === TournamentStatus::Signup))->toBeTrue()
        ->and($us->first()->starts_at->setTimezone('America/New_York')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-10 20:00')
        ->and(collect(NostrEvent::query()->findOrFail($us->first()->event_id)->payload()['tags'])->firstWhere(0, 'start_tzid')[1])->toBe('America/New_York');

    // A second run changes nothing: no rename, no move, no version, no notice, no second US cup.
    $state = Tournament::query()->orderBy('id')->get(['id', 'name', 'cup_series', 'cup_open_series', 'starts_at', 'event_id'])->toArray();
    $events = NostrEvent::query()->count();
    $notices = $players->map(fn (User $player): int => $player->notifications()->count())->all();

    splitMigration();

    expect(Tournament::query()->orderBy('id')->get(['id', 'name', 'cup_series', 'cup_open_series', 'starts_at', 'event_id'])->toArray())->toBe($state)
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and($players->map(fn (User $player): int => $player->notifications()->count())->all())->toBe($notices);
});

test('after the migration the EU numbering goes on where the old series stopped', function () {
    $rlDone = legacyCup('rocket-league', 1, CarbonImmutable::parse('2026-09-20 18:00', 'UTC'), 0,
        ['status' => TournamentStatus::Finished, 'cup_open_series' => null, 'cup_ended_at' => now()->subDays(2)]);
    $chess = legacyCup('chess', 1, CarbonImmutable::parse('2026-10-07 22:25', 'UTC'), 0);
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league']]);

    splitMigration();
    $chess->refresh()->forceFill(['status' => TournamentStatus::Finished])->save();
    cupTick();
    $this->travel(24)->hours();
    cupTick();

    expect(Tournament::query()->where('cup_open_series', 'chess-eu')->sole()->name)->toBe('Chess Casual Cup EU #2')
        ->and(Tournament::query()->where('cup_open_series', 'rocket-league-eu')->sole()->name)->toBe('Rocket League Casual Cup EU #2')
        ->and($rlDone->refresh()->name)->toBe('Rocket League Casual Cup #1');
});

test('the migration keeps a cup with players on its start when that is a slot, and stops without the league key', function () {
    // The old start lies exactly on a later EU slot: it stays.
    $cup = legacyCup('chess', 1, CarbonImmutable::parse('2026-10-17 18:00', 'UTC'), 1);
    $before = regionVersions($cup);
    $key = config('esports.league.nsec');
    config(['esports.league.nsec' => null]);

    // Fail closed and loud: the migration stops, nothing is half-done, the cup is still the old one.
    expect(fn () => splitMigration())->toThrow(TournamentRuleViolation::class)
        ->and($cup->refresh()->cup_series)->toBe('chess')
        ->and(Tournament::query()->where('cup_open_series', 'like', '%-us')->count())->toBe(0);

    config(['esports.league.nsec' => $key]);
    splitMigration();

    expect($cup->refresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-17 18:00')
        ->and($cup->name)->toBe('Chess Casual Cup EU #1')
        ->and(regionVersions($cup))->toBe($before + 1)
        ->and(User::query()->findOrFail($cup->signups()->firstOrFail()->members[0])->notifications()->count())->toBe(0);
});

/* ---------- Where cups show ----------------------------------------------------------------------------------- */

test('the game page, home, the index and a cup\'s own page show both regions\' cups, each start in the viewer\'s zone', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league']]);
    cupTick();
    $eu = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    $us = Tournament::query()->where('cup_open_series', 'chess-us')->sole();

    // A guest: the league's zone on the server (the browser rewrites it to its own).
    foreach (['/chess', route('home'), route('tournaments.index')] as $url) {
        $this->get($url)->assertOk()
            ->assertSeeInOrder(['Chess Casual Cup EU #1', 'Sat, 10 Oct 2026, 8:00 PM CEST', 'Chess Casual Cup US #1', 'Sun, 11 Oct 2026, 2:00 AM CEST'])
            ->assertSee('x-data="localTime({ at: '.$us->starts_at->getTimestampMs(), false);
    }

    $this->get('/games/rocket-league')->assertOk()->assertSeeInOrder(['Rocket League Casual Cup EU #1', 'Rocket League Casual Cup US #1'])->assertDontSee('Chess Casual Cup');

    // Signed in with a zone: that zone, and no browser rewrite.
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));
    $this->get('/chess')->assertOk()
        ->assertSeeInOrder(['Chess Casual Cup EU #1', 'Sat, 10 Oct 2026, 2:00 PM EDT', 'Chess Casual Cup US #1', 'Sat, 10 Oct 2026, 8:00 PM EDT'])
        ->assertDontSee('localTime({ at: '.$us->starts_at->getTimestampMs(), false);

    // The EU cup's page names the US cup, not itself.
    $html = $this->get(route('tournaments.show', $eu))->assertOk()->getContent();
    $other = str($html)->after('data-test="cup-other-regions"')->before('</ul>')->toString();

    expect($other)->toContain('Chess Casual Cup US #1', route('tournaments.show', $us))->not->toContain('Chess Casual Cup EU #1');
});

test('the rules name each region\'s start and the minimum sign-up; a bot note gives a US cup\'s start in New York time', function () {
    $this->get(route('rules'))->assertOk()
        ->assertSee('The league opens a casual cup per game and region (EU, US) on its own')
        ->assertSeeInOrder(['Start (EU)', 'Saturday 20:00 (Europe/Berlin)', 'Start (US)', 'Saturday 20:00 (America/New_York)', 'at least 2 days'], false);

    $us = app(CasualCups::class)->ensure('chess', 'us');

    // The bot writes no "#" (no hashtags in posts), so the number goes bare.
    expect(app(TournamentNotes::class)->content($us))->toContain('Chess Casual Cup US 1', 'Sat, 10 Oct 2026, 8:00 PM EDT');
});
