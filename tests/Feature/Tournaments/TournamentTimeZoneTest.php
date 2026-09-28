<?php

use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Tournament times: typed in Berlin, stored in UTC, shown with the zone
|--------------------------------------------------------------------------
|
| Admins type a start in the league's zone (Europe/Berlin), whatever zone
| their own profile names; it is stored as UTC and shown on the tournament
| page and every card in Berlin time with MEZ or MESZ, whichever the date
| has. An unchanged edit saves back the stored instant. A time in the spring
| gap or the autumn hour is refused. The browser twin of the preview runs
| under Node. The calendar file is UTC; the suspects command only reads.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    config(['esports.preseason.display_timezone' => 'Europe/Berlin']);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00', 'UTC'));
});

/**
 * The time fields any edit logged as changed.
 *
 * @return list<string>
 */
function timeFieldsLogged(): array
{
    return TournamentModerationEntry::query()->get()->flatMap(fn (TournamentModerationEntry $entry): array => array_values(array_intersect(array_keys($entry->details ?? []), ['starts_at', 'signup_closes_at'])))->values()->all();
}

/** Create a draft through the admin page, typed as the organizer would. */
function createAt(User $organizer, string $name, string $startsAt): Tournament
{
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', $name)->set('startsAt', $startsAt)
        ->call('create')->assertHasNoErrors();

    return Tournament::query()->where('name', $name)->sole();
}

test('a Berlin start is stored as UTC and shown in Berlin time with the zone of its date, in both languages', function (string $typed, string $utc, string $iso, array $en, array $de) {
    // A profile zone of its own must not change what the typed digits mean.
    $organizer = organizer();
    $organizer->forceFill(['timezone' => 'America/New_York'])->save();

    $tournament = createAt($organizer, 'Zone Cup', $typed);
    app(TournamentPublisher::class)->publish($tournament, $organizer, CarbonImmutable::parse('2026-10-01 10:00', 'UTC'));

    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe($utc);

    foreach (['en' => $en, 'de' => $de] as $locale => $shown) {
        $this->get(route('tournaments.show', $tournament).'?lang='.$locale)->assertOk()
            ->assertSeeInOrder(['data-test="tournament-when"', "datetime=\"{$iso}\"", ...$shown], false);
    }
})->with([
    'summer time: MESZ, UTC+2' => ['2026-10-03T20:00', '2026-10-03 18:00', '2026-10-03T18:00:00Z',
        ['Sat, 3 October 2026', '8:00 PM', 'CEST, Berlin'], ['Sa, 3. Oktober 2026', '20:00 Uhr', 'MESZ, Berlin']],
    'winter time: MEZ, UTC+1' => ['2026-12-05T20:00', '2026-12-05 19:00', '2026-12-05T19:00:00Z',
        ['Sat, 5 December 2026', '8:00 PM', 'CET, Berlin'], ['Sa, 5. Dezember 2026', '20:00 Uhr', 'MEZ, Berlin']],
]);

test('the landing page says how long until the start, the sign-up deadline and links the calendar file', function () {
    $tournament = openTournament(['starts_at' => CarbonImmutable::parse('2026-10-02 21:00', 'UTC')]);

    $this->get(route('tournaments.show', $tournament).'?lang=de')->assertOk()
        ->assertSee('startet in 5 T 9 Std')
        ->assertSee('Anmeldung bis '.LeagueTime::stamp($tournament->signup_closes_at))
        ->assertSee(route('tournaments.calendar', $tournament), false)
        ->assertSee('data-test="when-local"', false);

    app()->setLocale('en');
    expect(TournamentLanding::startsInText(3 * 3600 + 20 * 60 + 59))->toBe('starts in 3 h 20 min')
        ->and(TournamentLanding::startsInText(59))->toBe('starts in under a minute');
});

test('an edit shows the stored start in Berlin time and an unchanged save keeps the stored instant to the second', function () {
    $admin = organizer();
    $admin->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    $tournament = openTournament([
        'created_by_id' => $admin->id,
        'starts_at' => CarbonImmutable::parse('2026-10-03 18:00:37', 'UTC'),
    ]);
    $closes = $tournament->signup_closes_at->utc()->format('Y-m-d H:i:s');

    $page = Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('startsAt', '2026-10-03T20:00')
        ->assertSee('Time zone: Europe/Berlin (CEST, UTC+2)')
        ->assertSee('= Sat, 3 Oct 2026, 8:00 PM CEST · 6:00 PM UTC')
        ->call('save')->assertSet('error', '');

    // The factory's options are normalized by the chooser on the first save; the times never move.
    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-03 18:00:37')
        ->and($tournament->signup_closes_at->utc()->format('Y-m-d H:i:s'))->toBe($closes)
        ->and(timeFieldsLogged())->toBe([]);

    // A real change is read in Berlin, reloads as typed, and saves again without moving.
    $this->travel(3)->seconds();
    $page->set('startsAt', '2026-12-05T20:00')->call('save')->assertSet('error', '')
        ->assertSet('startsAt', '2026-12-05T20:00');
    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-05 19:00:00');

    $this->travel(3)->seconds();
    $page->call('save')->assertSet('error', '')->assertSet('notice', __('Nothing changed.'));
    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-05 19:00:00');
});

test('a time in the spring gap or the autumn hour is refused, and the times around them are read exactly', function (string $typed, ?string $utc, ?string $message) {
    $page = Livewire::actingAs(organizer())->test('pages::admin.tournament-create')
        ->set('name', 'Edge Cup')->set('startsAt', $typed)->call('create');

    if ($utc === null) {
        $page->assertHasErrors(['startsAt']);
        expect($page->errors()->first('startsAt'))->toBe($message)
            ->and(Tournament::query()->count())->toBe(0);

        return;
    }

    $page->assertHasNoErrors();
    expect(Tournament::query()->sole()->starts_at->utc()->format('Y-m-d H:i'))->toBe($utc);
})->with([
    'spring gap: 02:30 does not exist' => ['2026-03-29T02:30', null, '2:30 AM does not exist in Berlin on Sun, 29 Mar 2026: the clocks jump forward an hour. Pick another time.'],
    'autumn hour: 02:30 happens twice' => ['2026-10-25T02:30', null, '2:30 AM happens twice in Berlin on Sun, 25 Oct 2026: the clocks go back an hour. Pick another time.'],
    'before the autumn hour, still MESZ' => ['2026-10-25T01:59', '2026-10-24 23:59', null],
    'after the autumn hour, MEZ' => ['2026-10-25T03:00', '2026-10-25 02:00', null],
    'after the spring gap, MESZ' => ['2027-03-28T03:00', '2027-03-28 01:00', null],
    'not a time' => ['2026-10-03T25:00', null, 'Enter a date and a time.'],
]);

test('the league time reads the 2026 changes exactly: none in the gap, two in the autumn hour, earlier first', function () {
    expect(LeagueTime::candidates('2026-03-29T02:30'))->toBe([])
        ->and(array_map(fn ($at) => $at->format('Y-m-d H:i'), LeagueTime::candidates('2026-10-25T02:30')))->toBe(['2026-10-25 00:30', '2026-10-25 01:30'])
        ->and(LeagueTime::candidates('2026-02-30T20:00'))->toBeNull();

    app()->setLocale('de');
    expect(LeagueTime::preview('2026-03-29T02:30')['text'])->toBe('02:30 gibt es in Berlin am So, 29. Mär 2026 nicht: Die Uhren springen eine Stunde vor. Wähle eine andere Uhrzeit.')
        ->and(LeagueTime::preview('2026-10-03T20:00'))->toBe(['state' => 'ok', 'text' => '= Sa, 3. Okt 2026, 20:00 MESZ · 18:00 UTC'])
        ->and(LeagueTime::zoneLabel('2026-12-05T20:00'))->toBe('Zeitzone: Europa/Berlin (MEZ, UTC+1)');
});

test('a stored start inside the autumn hour survives an unchanged edit, the one defined exception to the refusal', function () {
    $admin = organizer();
    $tournament = openTournament(['created_by_id' => $admin->id, 'starts_at' => CarbonImmutable::parse('2026-10-25 01:30', 'UTC')]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('startsAt', '2026-10-25T02:30')
        ->call('save')->assertHasNoErrors()->assertSet('error', '');

    expect(timeFieldsLogged())->toBe([]);

    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 01:30');
});

test('the browser twin of the preview builds the same strings as the server, across both changes', function () {
    $inputs = ['', '2026-10-03T20:00', '2026-12-05T20:00', '2026-03-29T01:59', '2026-03-29T02:30', '2026-03-29T03:00',
        '2026-10-25T01:59', '2026-10-25T02:30', '2026-10-25T03:00', '2026-12-31T23:30', '2027-01-01T00:15', '2026-10-03T12:05', 'nonsense'];
    $sets = [];

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        $sets[] = ['config' => LeagueTime::client(), 'cases' => array_map(fn (string $input): array => [
            'input' => $input, ...LeagueTime::preview($input), 'zone' => LeagueTime::zoneLabel($input),
        ], $inputs)];
    }

    $fixture = tempnam(sys_get_temp_dir(), 'league-time-');
    file_put_contents($fixture, json_encode(['now' => (int) now()->getTimestampMs(), 'sets' => $sets]));
    $run = Process::path(base_path())->env(['LEAGUE_TIME_FIXTURE' => $fixture])->timeout(60)->run(['node', '--test', 'tests/js/leagueTime.test.mjs']);
    unlink($fixture);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ fail 0');
});

test('the calendar file is public, in UTC and folded, and a draft has none', function () {
    $tournament = openTournament(['name' => 'Halving Cup; the long one, with a name that runs past seventy-five octets']);
    $tournament->forceFill(['starts_at' => CarbonImmutable::parse('2026-10-03 18:00', 'UTC')])->save();

    $response = $this->get(route('tournaments.calendar', $tournament))->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $body = $response->getContent();

    expect($body)->toContain("DTSTART:20261003T180000Z\r\n")
        ->toContain('SUMMARY:Halving Cup\; the long one\, with a name')
        ->not->toContain('TZID')
        ->and(collect(explode("\r\n", (string) $body))->map(fn (string $line): int => strlen($line))->max())->toBeLessThanOrEqual(75);

    $draft = Tournament::factory()->create(['created_by_id' => organizer()->id]);
    $this->get(route('tournaments.calendar', $draft))->assertNotFound();
});

test('cards and lists name the zone: the index, the game page and the admin list', function () {
    $tournament = openTournament(['starts_at' => CarbonImmutable::parse('2026-10-03 18:00', 'UTC')], rocketLeague: true);

    // The index's cards read the start like the cup board: day, clock and the city of the zone (OrganizerBoard).
    $this->get(route('tournaments.index').'?lang=de')->assertOk()
        ->assertSeeInOrder(['datetime="2026-10-03T18:00:00Z"', '>20:00</b>', '>Sa, 3. Okt</span>', '>Berlin</span>'], false);
    $this->get(route('games.rocket-league').'?lang=en')->assertOk()->assertSee('Sat, 3 Oct 2026, 8:00 PM CEST');
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $this->actingAs($admin)->get(route('admin.tournaments').'?lang=en')->assertOk()->assertSee('Sat, 3 Oct 2026, 8:00 PM CEST');
    expect($tournament->status)->toBe(TournamentStatus::Signup);
});

test('the suspects command lists starts that may have been read as UTC and changes nothing', function () {
    $before = CarbonImmutable::parse('2026-09-20 10:00', 'UTC');
    $suspect = Tournament::factory()->create(['name' => 'Early Cup', 'created_by_id' => organizer()->id, 'created_at' => $before, 'starts_at' => CarbonImmutable::parse('2026-10-03 19:00', 'UTC')]);
    $resaved = Tournament::factory()->create(['name' => 'Fixed Cup', 'created_by_id' => organizer()->id, 'created_at' => $before]);
    TournamentModerationEntry::query()->create(['tournament_id' => $resaved->id, 'user_name' => 'x', 'action' => 'edited', 'details' => ['starts_at' => ['a', 'b']]]);
    Tournament::factory()->create(['name' => 'Late Cup', 'created_by_id' => organizer()->id]);
    $snapshot = Tournament::query()->orderBy('id')->get()->map->only(['id', 'starts_at', 'updated_at'])->toJson();

    $this->artisan('tournaments:zone-suspects')->assertSuccessful()
        ->expectsOutputToContain('Read-only')
        ->expectsOutputToContain('1 suspect(s)');

    Artisan::call('tournaments:zone-suspects');
    $output = Artisan::output();

    expect($output)->toContain('Early Cup')->toContain('2026-10-03 19:00 UTC')->toContain('2026-10-03 17:00 UTC')
        ->not->toContain('Fixed Cup')->not->toContain('Late Cup')
        ->and(Tournament::query()->orderBy('id')->get()->map->only(['id', 'starts_at', 'updated_at'])->toJson())->toBe($snapshot);
});
