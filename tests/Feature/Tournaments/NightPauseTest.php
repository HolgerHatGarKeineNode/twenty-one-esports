<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Models\NotificationDigestItem;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Dock\UpcomingEvents;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\CupSchedules;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\NightPause;
use App\Support\Tournaments\NightPauseNotices;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The night pause (NightPause, NightPauseNotices)
|--------------------------------------------------------------------------
|
| From 24:00 to 18:00 (Berlin) by default no new match starts and no league
| clock runs; a match under way may finish, its deadlines stop and run on at
| 18:00 with the time they had left. A tournament overrides switch and times.
| phpunit.xml switches the league default off (ESPORTS_NIGHT_PAUSE=false), so
| every other test runs at any hour: this file switches it on.
|
*/

beforeEach(function () {
    config(['esports.tournaments.night_pause.enabled' => true]);
});

/** A moment typed in Berlin. */
function berlin(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Berlin');
}

/** The default window, 24:00 to 18:00. */
function nightWindow(string $until = '00:00', string $resume = '18:00'): array
{
    return NightPause::window($until, $resume) ?? throw new RuntimeException('invalid window');
}

/** An online players-mode Rocket League 1v1 knockout of two, drawn and synced now. */
function nightDuel(array $attributes = []): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'night-'.fake()->unique()->numberBetween(1, 1_000_000),
        ...$attributes,
    ]);

    foreach ([1, 2] as $index) {
        $user = User::factory()->create();
        $signup = TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'members' => [$user->id]]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'tournament_signup_id' => $signup->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

function nightSeries(Tournament $tournament): ?SeriesMatch
{
    return SeriesMatch::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->latest('id')->first();
}

/* ---------- The clock freeze ----------------------------------------------------------------------------- */

test('a deadline crossing midnight stops at 24:00 and runs on at 18:00 with the time it had left', function () {
    $due = NightPause::addSeconds(nightWindow(), berlin('2026-10-10 23:40'), 30 * 60);

    expect($due->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 18:10');
});

test('a clock started inside the pause counts from its end; one that reaches 24:00 with nothing left stays there', function () {
    expect(NightPause::addSeconds(nightWindow(), berlin('2026-10-11 01:00'), 600)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 18:10')
        ->and(NightPause::addSeconds(nightWindow(), berlin('2026-10-10 23:30'), 1800)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 00:00')
        // Durchspielen: no window, plain addition.
        ->and(NightPause::addSeconds(null, berlin('2026-10-10 23:40'), 1800)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 00:10');
});

test('a custom 23:00 to 12:00 pause crosses midnight', function () {
    $window = nightWindow('23:00', '12:00');

    expect(NightPause::addSeconds($window, berlin('2026-10-10 22:50'), 1200)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 12:10')
        ->and(NightPause::current($window, berlin('2026-10-11 11:59')))->not->toBeNull()
        ->and(NightPause::current($window, berlin('2026-10-11 12:00')))->toBeNull()
        ->and(NightPause::current($window, berlin('2026-10-10 22:59')))->toBeNull();
});

test('on the daylight saving nights the pause follows the wall clock: 19 real hours in October, 17 in March', function () {
    $autumn = NightPause::current(nightWindow(), berlin('2026-10-25 03:30'));
    $spring = NightPause::current(nightWindow(), berlin('2027-03-28 05:00'));
    $due = NightPause::addSeconds(nightWindow(), berlin('2026-10-24 23:50'), 1200);

    expect(($autumn[1]->getTimestamp() - $autumn[0]->getTimestamp()) / 3600)->toBe(19)
        ->and(($spring[1]->getTimestamp() - $spring[0]->getTimestamp()) / 3600)->toBe(17)
        ->and($due->setTimezone('Europe/Berlin')->format('Y-m-d H:i T'))->toBe('2026-10-25 18:10 CET');
});

/* ---------- Where it applies ----------------------------------------------------------------------------- */

test('it applies to online players-mode tournaments of a minute game, and an organizer overrides it', function () {
    $online = Tournament::factory()->make(['game' => 'rocket-league', 'mode' => '1v1']);

    expect(NightPause::windowOf($online))->toBe(['until' => '00:00', 'resume' => '18:00'])
        ->and(NightPause::windowOf(Tournament::factory()->make(['night_pause' => false])))->toBeNull()
        ->and(NightPause::windowOf(Tournament::factory()->make(['night_pause_until' => '23:00', 'night_pause_resume' => '12:00'])))->toBe(['until' => '23:00', 'resume' => '12:00'])
        ->and(NightPause::windowOf(Tournament::factory()->make(['on_site' => true, 'stations' => 4])))->toBeNull()
        ->and(NightPause::windowOf(Tournament::factory()->make(['results_mode' => TournamentResultsMode::Director])))->toBeNull()
        ->and(NightPause::windowOf(Tournament::factory()->make(['mode' => 'correspondence', 'options' => FormatOptions::defaults(GameProfile::for('chess', 'correspondence'))->toArray()])))->toBeNull()
        // A casual cup too, on its region's clock (user, 2026-10-10: "Auch Casual Cups").
        ->and(NightPause::windowOf(Tournament::factory()->make(['cup_series' => 'chess-eu'])))->toBe(['until' => '00:00', 'resume' => '18:00'])
        ->and(NightPause::windowOf(Tournament::factory()->make(['cup_series' => 'chess-us'])))->toBe(['until' => '00:00', 'resume' => '18:00', 'zone' => 'America/New_York']);

    config(['esports.tournaments.night_pause.enabled' => false]);
    expect(NightPause::windowOf($online))->toBeNull()
        ->and(NightPause::windowOf(Tournament::factory()->make(['night_pause' => true])))->not->toBeNull();
});

test('a lobby report deadline stops in the pause', function () {
    $this->travelTo(berlin('2026-10-10 22:00'));
    $tournament = Tournament::factory()->make(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll, 'starts_at' => now()]);
    $plain = now()->toImmutable()->addMinutes(Lobbies::plannedMinutes('age-of-empires-2') + (int) (Lobbies::config('age-of-empires-2')['report_minutes'] ?? 60));
    $before = $plain->setTimezone('Europe/Berlin')->startOfDay()->getTimestamp() - now()->getTimestamp();

    expect($plain->setTimezone('Europe/Berlin')->format('Y-m-d'))->toBe('2026-10-11')
        ->and(Lobbies::reportBy($tournament, now()->toImmutable())->getTimestamp())
        ->toBe(berlin('2026-10-11 18:00')->getTimestamp() + ($plain->getTimestamp() - now()->getTimestamp() - $before));
})->skip(fn (): bool => ! Lobbies::isLobbyGame('age-of-empires-2'), 'AoE2 is no lobby game here');

/* ---------- A tournament through the night ------------------------------------------------------------------ */

test('a match under way at 24:00 is played to its end; its report deadline stands in the pause and runs on at 18:00', function () {
    $this->travelTo(berlin('2026-10-10 23:40'));
    $series = nightSeries(nightDuel());
    SeriesMatch::query()->whereKey($series->id)->update(['ready_at_challenger' => now(), 'ready_at_challenged' => now()]);
    $series->refresh();

    // The round clock's report minutes: 20 before midnight, the rest from 18:00.
    expect($series->deadlines['night_pause'])->toBe(['until' => '00:00', 'resume' => '18:00'])
        ->and($series->deadlines['report_minutes'])->toBeGreaterThan(20)
        ->and($series->reportDueAt()->getTimestamp())->toBe(berlin('2026-10-11 18:00')->addMinutes($series->deadlines['report_minutes'] - 20)->getTimestamp());

    // 00:40: the match ended, the winner reports in the pause; the other side answers within 10 minutes of league clock.
    $this->travelTo(berlin('2026-10-11 00:40'));
    $winner = User::query()->findOrFail($series->rosterSide('challenger')[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        app(SeriesService::class)->saveLiveGame($series->refresh(), $winner, $index, 3, 1, null);
    }

    app(SeriesService::class)->report($series->refresh(), $winner, []);

    expect($series->refresh()->status)->toBe(SeriesStatus::Reported)
        ->and($series->responseDueAt()->getTimestamp())->toBe(berlin('2026-10-11 18:10')->getTimestamp())
        ->and(TournamentWaits::of($series->tournamentMatch->tournament)[0]->paused)->toBeTrue();

    $this->travelTo(berlin('2026-10-11 18:09'));
    expect(app(TournamentScheduler::class)->tick()['confirmed'])->toBe(0);

    $this->travelTo(berlin('2026-10-11 18:10:01'));
    expect(app(TournamentScheduler::class)->tick()['confirmed'])->toBe(1);
});

test('no new match starts in the pause; the first tick at 18:00 starts it', function () {
    $this->travelTo(berlin('2026-10-11 00:30'));
    $tournament = nightDuel();
    $wait = TournamentWaits::of($tournament)[0];

    expect(nightSeries($tournament))->toBeNull()
        ->and($wait->state)->toBe('not_started')
        ->and($wait->consequence)->toBe('Night pause: the league starts it at :time');

    $this->travelTo(berlin('2026-10-11 17:59'));
    app(TournamentScheduler::class)->tick();
    expect(nightSeries($tournament))->toBeNull();

    $this->travelTo(berlin('2026-10-11 18:00'));
    app(TournamentScheduler::class)->tick();
    expect(nightSeries($tournament)?->start_at?->getTimestamp())->toBe(berlin('2026-10-11 18:00')->getTimestamp());
});

test('a tournament set to play through starts and counts at night', function () {
    $this->travelTo(berlin('2026-10-11 00:30'));
    $series = nightSeries(nightDuel(['night_pause' => false]));

    expect($series)->not->toBeNull()
        ->and($series->deadlines['night_pause'])->toBeFalse()
        ->and($series->autoNoshowAt()->getTimestamp())->toBe(berlin('2026-10-11 01:00')->getTimestamp());
});

/* ---------- Telling the players --------------------------------------------------------------------------- */

test('each player with an open match gets one message at the pause start and one 30 minutes before the restart', function () {
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $this->travelTo(berlin('2026-10-10 23:40'));
    $tournament = nightDuel();

    $this->travelTo(berlin('2026-10-11 00:01'));
    expect(app(TournamentScheduler::class)->tick()['night'])->toBe(2)
        ->and(app(TournamentScheduler::class)->tick()['night'])->toBe(0);

    $this->travelTo(berlin('2026-10-11 17:30'));
    expect(app(TournamentScheduler::class)->tick()['night'])->toBe(2)
        ->and(app(TournamentScheduler::class)->tick()['night'])->toBe(0);

    $texts = Queue::pushed(SendNostrDm::class)->map(fn (SendNostrDm $job): string => $job->text)->values()->all();

    expect($texts)->toHaveCount(4)
        ->and($texts[0])->toContain('Night pause in '.$tournament->name)->toContain('Player')
        ->and($texts[3])->toContain($tournament->name.' goes on in 30 minutes')
        ->and(implode("\n", $texts))->not->toContain('#');
});

test('the banner and the dock say it big: soon before 24:00, then the restart and the player\'s match', function () {
    $this->travelTo(berlin('2026-10-10 23:10'));
    $tournament = nightDuel();
    $player = User::query()->findOrFail(nightSeries($tournament)->rosterSide('challenger')[0]);
    $this->actingAs($player);

    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $tournament])
        ->assertSee('data-state="soon"', false)
        ->assertSee('At 24:00 the night pause starts; a match under way may be played to its end.');

    $this->travelTo(berlin('2026-10-11 00:20'));
    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $tournament->refresh()])
        ->assertSee('data-state="paused"', false)
        ->assertSee('18:00')
        ->assertSee('Your match against Player 2 goes on at 18:00.');

    $item = app(UpcomingEvents::class)->tournaments($player, todayOnly: true)->firstWhere('key', 'tournament-'.$tournament->id);

    expect($item?->group)->toBe('live')
        ->and($item?->line)->toStartWith('Night pause: your match against Player 2 goes on at 18:00')
        ->and($item?->tick['endsAt'])->toBe(berlin('2026-10-11 18:00')->getTimestampMs());

    $this->travelTo(berlin('2026-10-11 18:00'));
    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $tournament->refresh()])->assertDontSee('night-pause-banner', false);
});

test('drawn at 16:30 for an 18:00 start, the tournament waits for its start, not for a night pause: no banner, no dock item, no message', function () {
    // DerCaddy, 2026-11-13: Blitz Blockli Abend, start 18:00 by vote, drawn at the sign-up close; at 16:49 the page said
    // "Nachtpause: Bis 18:00 startet kein neues Match", as if play had stopped. The default pause runs 24:00 to 18:00.
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $this->travelTo(berlin('2026-11-13 16:30'));
    $tournament = nightDuel(['starts_at' => berlin('2026-11-13 18:00')->utc(), 'draw_committed_at' => now()]);
    $player = User::query()->whereKey($tournament->participants()->value('user_id'))->firstOrFail();

    $this->travelTo(berlin('2026-11-13 16:49'));
    $this->actingAs($player);

    expect(NightPause::activeFor($tournament))->not->toBeNull()
        ->and(NightPause::heldForStart($tournament))->toBeTrue()
        ->and(NightPauseNotices::state($tournament))->toBeNull()
        ->and(app(TournamentScheduler::class)->tick()['night'])->toBe(0)
        ->and(Queue::pushed(SendNostrDm::class))->toHaveCount(0);
    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $tournament])->assertDontSee('night-pause-banner', false);

    $item = app(UpcomingEvents::class)->tournaments($player, todayOnly: true)->firstWhere('key', 'tournament-'.$tournament->id);
    $wait = TournamentWaits::of($tournament->refresh())[0];

    expect($item?->title)->toBe('Tournament')
        ->and($wait->state)->toBe('not_started')
        ->and($wait->consequence)->toBe('The league starts it at :time')
        ->and($wait->timeAt?->getTimestamp())->toBe(berlin('2026-11-13 18:00')->getTimestamp());

    $this->travelTo(berlin('2026-11-13 18:00'));
    app(TournamentScheduler::class)->tick();
    expect(nightSeries($tournament)?->start_at?->getTimestamp())->toBe(berlin('2026-11-13 18:00')->getTimestamp());
});

test('a start inside the pause still waits for the pause: drawn at 16:00 for 17:00, it says the night pause until 18:00', function () {
    $this->travelTo(berlin('2026-11-13 16:00'));
    $tournament = nightDuel(['starts_at' => berlin('2026-11-13 17:00')->utc(), 'draw_committed_at' => now()]);

    $this->travelTo(berlin('2026-11-13 16:30'));
    expect(NightPauseNotices::state($tournament)['state'] ?? null)->toBe('paused')
        ->and(TournamentWaits::of($tournament)[0]->consequence)->toBe('Night pause: the league starts it at :time');
});

test('drawn for an 18:00 start, the tournament gets no "night pause soon" banner before 24:00: that pause is over by its start; one under way gets it in the same minute', function () {
    $this->travelTo(berlin('2026-11-13 23:00'));
    $waiting = nightDuel(['starts_at' => berlin('2026-11-14 18:00')->utc(), 'draw_committed_at' => now()]);
    $playing = nightDuel(['starts_at' => berlin('2026-11-13 22:00')->utc(), 'draw_committed_at' => berlin('2026-11-13 21:00')->utc()]);

    $this->travelTo(berlin('2026-11-13 23:10'));

    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $waiting])->assertDontSee('night-pause-banner', false);
    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $playing])->assertSee('data-state="soon"', false);
});

test('a start postponed while a series is under way does not hide the night pause: the banner and the wait say the series\' clock stands until 18:00', function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(berlin('2026-11-13 23:40'));
    $tournament = nightDuel(['starts_at' => berlin('2026-11-13 19:00')->utc(), 'draw_committed_at' => berlin('2026-11-13 16:30')->utc(), 'created_by_id' => organizer()->id]);
    $series = nightSeries($tournament);
    SeriesMatch::query()->whereKey($series->id)->update(['ready_at_challenger' => now(), 'ready_at_challenged' => now()]);

    $this->travelTo(berlin('2026-11-14 00:30'));
    app(TournamentEditor::class)->update($tournament, $tournament->creator, ['starts_at' => berlin('2026-11-14 19:00')->utc()]);
    $wait = TournamentWaits::of($tournament->refresh())[0];

    // The series pinned the window at its pairing: 20 of its report minutes ran before midnight, the rest runs from 18:00.
    expect(NightPause::heldForStart($tournament))->toBeTrue()
        ->and($series->refresh()->reportDueAt()->getTimestamp())->toBe(berlin('2026-11-14 18:00')->addMinutes($series->deadlines['report_minutes'] - 20)->getTimestamp())
        ->and($wait->resumesAt?->getTimestamp())->toBe(berlin('2026-11-14 18:00')->getTimestamp())
        ->and($wait->paused)->toBeTrue()
        ->and(NightPauseNotices::state($tournament)['state'] ?? null)->toBe('paused');
    $this->blade('<x-tournament-night-pause :tournament="$t" />', ['t' => $tournament])->assertSee('data-state="paused"', false);
});

test('a small cup drawn for its evening at 20:00 waits for the evening, not for a night pause that ends at 18:00', function () {
    $this->travelTo(berlin('2026-11-13 16:30'));
    $cup = runningCup(2, TournamentFormat::SingleElimination, ['bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    $cup->forceFill(['starts_at' => berlin('2026-11-13 20:00')->utc(), 'draw_committed_at' => now()])->save();
    $wait = TournamentWaits::of($cup->refresh())[0];

    expect(NightPauseNotices::state($cup))->toBeNull()
        ->and($wait->timeAt?->getTimestamp())->toBe(berlin('2026-11-13 20:00')->getTimestamp());

    $this->travelTo(berlin('2026-11-13 18:00'));
    cupTick();
    expect(SeriesMatch::query()->count())->toBe(0);

    $this->travelTo(berlin('2026-11-13 20:00'));
    cupTick();
    expect(SeriesMatch::query()->count())->toBe(1);
});

test('drawn at 22:00 for a start at 00:30 inside the coming night pause, the match is announced for the end of that pause, not for 00:30', function () {
    $this->travelTo(berlin('2026-11-13 22:00'));
    $tournament = nightDuel(['starts_at' => berlin('2026-11-14 00:30')->utc(), 'draw_committed_at' => now()]);

    $this->travelTo(berlin('2026-11-13 22:01'));
    $wait = TournamentWaits::of($tournament)[0];

    expect(NightPause::activeFor($tournament))->toBeNull()
        ->and($wait->consequence)->toBe('Night pause: the league starts it at :time')
        ->and($wait->timeAt?->getTimestamp())->toBe(berlin('2026-11-14 18:00')->getTimestamp());

    $this->travelTo(berlin('2026-11-14 00:30'));
    app(TournamentScheduler::class)->tick();
    expect(nightSeries($tournament))->toBeNull();

    $this->travelTo(berlin('2026-11-14 18:00'));
    app(TournamentScheduler::class)->tick();
    expect(nightSeries($tournament)?->start_at?->getTimestamp())->toBe(berlin('2026-11-14 18:00')->getTimestamp());
});

/* ---------- The organizer's setting ------------------------------------------------------------------------- */

test('the organizer switches the night pause off or sets its times in the edit page; bad times are refused', function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament();

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee(__('Night pause'))
        ->call('toggleNightPause')
        ->set('nightPauseUntil', '24:00')
        ->set('nightPauseResume', '12:00')
        ->call('save')
        ->assertSet('error', '')
        ->assertHasNoErrors();

    expect($tournament->refresh()->only(['night_pause', 'night_pause_until', 'night_pause_resume']))
        ->toBe(['night_pause' => false, 'night_pause_until' => '00:00', 'night_pause_resume' => '12:00']);

    $editor = app(TournamentEditor::class);
    expect(fn () => $editor->update($tournament, $tournament->creator, ['night_pause_until' => '25:00']))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $editor->update($tournament, $tournament->creator, ['night_pause_resume' => '00:00']))->toThrow(TournamentRuleViolation::class);
});

/* ---------- Casual cups (user, 2026-10-10: "Auch Casual Cups") ----------------------------------------------- */

test('a cup opens no round in its pause; at 18:00 it opens, and a window that would close at night keeps its rest from the next restart', function () {
    config(['esports.casual_cups.window_hours' => 12]);
    $this->travelTo(berlin('2026-10-11 00:30'));
    $cup = runningCup(4);

    expect(cupTick()['cups']['rounds'])->toBe(0)
        ->and(openCupMatches($cup))->toHaveCount(0);

    // 18:00 + 12 h = 06:00, six hours into the next pause: it ends six hours after that pause, at 24:00.
    $this->travelTo(berlin('2026-10-11 18:00'));
    expect(cupTick()['cups']['rounds'])->toBe(1)
        ->and(openCupMatches($cup)->first()->round->window_ends_at->getTimestamp())->toBe(berlin('2026-10-13 00:00')->getTimestamp());
});

test('a cup series\' casual clocks stop in the pause, and no time in the pause can be proposed', function () {
    $series = new SeriesMatch([
        'origin' => SeriesMatch::ORIGIN_CUP, 'status' => SeriesStatus::Accepted, 'start_at' => berlin('2026-10-10 23:30')->utc(),
        'ready_at_challenger' => berlin('2026-10-10 23:30')->utc(), 'ready_at_challenged' => berlin('2026-10-10 23:30')->utc(),
        'casual' => ['report_minutes' => 60, 'night_pause' => ['until' => '00:00', 'resume' => '18:00']],
    ]);
    $plain = new SeriesMatch([
        'origin' => SeriesMatch::ORIGIN_CUP, 'status' => SeriesStatus::Accepted, 'start_at' => berlin('2026-10-10 23:30')->utc(),
        'ready_at_challenger' => berlin('2026-10-10 23:30')->utc(), 'ready_at_challenged' => berlin('2026-10-10 23:30')->utc(),
        'casual' => ['report_minutes' => 60, 'night_pause' => false],
    ]);

    expect($series->casualReportDueAt()->getTimestamp())->toBe(berlin('2026-10-11 18:30')->getTimestamp())
        ->and($plain->casualReportDueAt()->getTimestamp())->toBe(berlin('2026-10-11 00:30')->getTimestamp());

    $this->travelTo(berlin('2026-10-11 19:00'));
    $cup = runningCup(2, TournamentFormat::DoubleElimination, ['grandFinal' => 'single'], 'rocket-league', '1v1');
    cupTick();
    $match = openCupMatches($cup)->first();
    $player = User::query()->findOrFail($match->slots[0]->participant->user_id);

    expect(fn () => app(CupSchedules::class)->propose($match, $player, [berlin('2026-10-12 01:00')->getTimestamp()]))
        ->toThrow(SeriesRuleViolation::class, 'No suggested time may lie in the night pause.');
    app(CupSchedules::class)->propose($match, $player, [berlin('2026-10-11 21:00')->getTimestamp()]);
});

/* ---------- "Termin-Erinnerung sofort" ---------------------------------------------------------------------- */

test('the restart reminder skips the daily digest; the pause start follows it; DMs off get neither', function () {
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $this->travelTo(berlin('2026-10-10 23:40'));
    $tournament = nightDuel();
    [$digest, $off] = User::query()->whereIn('id', $tournament->participants()->pluck('user_id'))->orderBy('id')->get()->all();
    $digest->forceFill(['chess_settings' => ['digest' => ['tournament_news' => true]]])->save();
    $off->forceFill(['chess_settings' => ['dm' => false]])->save();

    $this->travelTo(berlin('2026-10-11 00:01'));
    app(TournamentScheduler::class)->tick();

    expect(Queue::pushed(SendNostrDm::class))->toHaveCount(0)
        ->and(NotificationDigestItem::query()->where('user_id', $digest->id)->count())->toBe(1);

    $this->travelTo(berlin('2026-10-11 17:30'));
    app(TournamentScheduler::class)->tick();
    $sent = Queue::pushed(SendNostrDm::class);

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->user->is($digest))->toBeTrue()
        ->and($sent->first()->text)->toContain('goes on in 30 minutes')
        ->and(NotificationDigestItem::query()->where('user_id', $digest->id)->count())->toBe(1);
});
