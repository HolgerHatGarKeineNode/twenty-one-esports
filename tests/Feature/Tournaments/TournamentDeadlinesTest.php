<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Tournament deadlines and the tournament clock (P18, slice 2)
|--------------------------------------------------------------------------
|
| `tournaments:tick` applies each deadline of a players-mode series once:
| an unanswered no-show becomes a forfeit, a series nobody reported joins
| the admin queue, an unanswered report is confirmed by the league. The
| league's decisions move no Elo. A tournament's own deadlines beat the
| config, are pinned at the pairing (an edit after the start only reaches
| later pairings) and are logged. The tick's heartbeat drives the stale
| warning on the admin tournaments page.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->freezeTime();
    config(['esports.series.noshow_minutes' => 15, 'esports.tournaments.report_hours' => 2, 'esports.tournaments.response_minutes' => 30]);
});

/**
 * A running players-mode RL 1v1 final between two solo players (casual: no
 * season), its series paired now.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Tournament, 1: SeriesMatch, 2: User, 3: User}
 */
function deadlineDuel(array $attributes = []): array
{
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'deadline-duel-'.fake()->unique()->numberBetween(1, 1_000_000),
        ...$attributes,
    ]);
    $players = [User::factory()->create(), User::factory()->create()];

    foreach ($players as $index => $player) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();

    return [$tournament->refresh(), $series, User::query()->findOrFail($series->rosterSide('challenger')[0]), User::query()->findOrFail($series->rosterSide('challenged')[0])];
}

/** `$winner` enters a clean series win and reports it (casual: nothing to sign). */
function deadlineReport(SeriesMatch $series, User $winner): SeriesMatch
{
    $service = app(SeriesService::class);
    $challenger = $series->refresh()->captainSideOf($winner) === 'challenger';

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
    }

    $service->report($series, $winner, []);

    return $series->refresh();
}

/** @return array{closed: int, drawn: int, forfeited: int, overdue: int, confirmed: int} */
function deadlineTick(): array
{
    return app(TournamentScheduler::class)->tick();
}

function deadlineAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/* ---------- The transitions, each once ---------------------------------------------------------------------- */

test('an unanswered no-show becomes a forfeit at the response deadline, once and unrated', function () {
    [$tournament, $series, $a] = deadlineDuel();

    $this->travel(15)->minutes();
    app(SeriesService::class)->reportNoShow($series, $a);

    $this->travel(29)->minutes();
    expect(deadlineTick()['forfeited'])->toBe(0)
        ->and($series->refresh()->status)->toBe(SeriesStatus::Accepted)
        ->and($series->nextDeadline())->toMatchArray(['kind' => 'noshow', 'side' => 'challenged']);

    $this->travel(1)->minutes();
    expect(deadlineTick()['forfeited'])->toBe(1);

    $finished = $series->refresh()->finished_at;
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole();

    expect($series->status)->toBe(SeriesStatus::Resolved)
        ->and($series->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($series->winner)->toBe('challenger')
        ->and($series->resolved_by_id)->toBeNull()
        ->and($series->nextDeadline())->toBeNull()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($match->result)->toMatchArray(['winner' => 0, 'forfeit' => true, 'by' => 'league', 'label' => 'forfeit'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    $this->travel(5)->minutes();
    expect(deadlineTick()['forfeited'])->toBe(0)
        ->and($series->refresh()->finished_at->equalTo($finished))->toBeTrue();
});

test('a no-show answered with a game entry is not forfeited', function () {
    [, $series, $a, $b] = deadlineDuel();

    $this->travel(15)->minutes();
    app(SeriesService::class)->reportNoShow($series, $a);
    app(SeriesService::class)->saveLiveGame($series, $b, 0, 1, 3, null);

    $this->travel(31)->minutes();
    expect(deadlineTick()['forfeited'])->toBe(0)
        ->and($series->refresh()->status)->toBe(SeriesStatus::Accepted);
});

test('a series nobody reported joins the admin queue at the report deadline, once', function () {
    [, $series] = deadlineDuel();
    $admin = deadlineAdmin();

    expect($series->nextDeadline())->toMatchArray(['kind' => 'report', 'side' => null])
        ->and($series->nextDeadline()['at']->equalTo($series->start_at->copy()->addHours(2)))->toBeTrue();

    $this->travel(119)->minutes();
    expect(deadlineTick()['overdue'])->toBe(0)
        ->and(SeriesMatch::query()->openCase()->count())->toBe(0);

    $this->travel(1)->minutes();
    expect(deadlineTick()['overdue'])->toBe(1)
        ->and(SeriesMatch::query()->openCase()->pluck('id')->all())->toBe([$series->id])
        ->and(SeriesService::isOpenCase($series->refresh()))->toBeTrue()
        ->and($series->nextDeadline())->toBeNull();
    Livewire::actingAs($admin)->test('pages::admin.disputes')->assertSee('Report overdue')->assertSee($series->label());

    $overdue = $series->overdue_at;
    $this->travel(3)->minutes();

    expect(deadlineTick()['overdue'])->toBe(0)
        ->and($series->refresh()->overdue_at->equalTo($overdue))->toBeTrue()
        ->and($series->status)->toBe(SeriesStatus::Accepted);
});

test('an unanswered report is confirmed by the league at the response deadline, once and unrated', function () {
    [$tournament, $series, $a] = deadlineDuel();
    $this->travel(20)->minutes();
    $series = deadlineReport($series, $a);

    expect($series->nextDeadline())->toMatchArray(['kind' => 'response', 'side' => 'challenged']);

    $this->travel(29)->minutes();
    expect(deadlineTick()['confirmed'])->toBe(0);

    $this->travel(1)->minutes();
    expect(deadlineTick()['confirmed'])->toBe(1);

    $series->refresh();
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole();

    expect($series->status)->toBe(SeriesStatus::Resolved)
        ->and($series->resolution)->toBe(SeriesResolution::Admin)
        ->and($series->winner)->toBe('challenger')
        ->and($series->result_games)->toBe($series->latestReport->games)
        ->and($series->resolved_roster)->toBe($series->latestReport->roster)
        ->and($series->resolution_reason)->toContain('confirmed by the league, unrated')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($match->result)->toMatchArray(['winner' => 0, 'forfeit' => false, 'by' => 'league'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    expect(deadlineTick()['confirmed'])->toBe(0);
});

test('a ladder series outside a tournament gets no league deadlines', function () {
    $series = SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted, 'start_at' => now()->subDay()]);

    expect($series->deadlines)->toBeNull()
        ->and($series->nextDeadline())->toBeNull()
        ->and(deadlineTick())->toMatchArray(['forfeited' => 0, 'overdue' => 0, 'confirmed' => 0])
        ->and($series->refresh()->overdue_at)->toBeNull();
});

/* ---------- Idempotency under a concurrent run ----------------------------------------------------------------- */

test('two ticks racing on one due report confirm it once: the late one finds the row taken', function () {
    [, $series, $a] = deadlineDuel();
    $series = deadlineReport($series, $a);
    $this->travel(31)->minutes();

    // The other server's tick runs in full exactly while this tick holds the series as still reported: the
    // report is read twice per series (the tick's candidate list, then SeriesService::autoConfirm() loading
    // the series afresh), and the race starts at the second read, after the series row was hydrated and
    // before the update. Past every check in PHP, only the database state can stop the second decision.
    $seen = 0;
    $raced = false;
    SeriesReport::retrieved(function (SeriesReport $loaded) use ($series, &$seen, &$raced): void {
        if ($loaded->series_match_id !== $series->id || $raced || ++$seen < 2) {
            return;
        }

        $raced = true;
        expect(app(TournamentScheduler::class)->tick()['confirmed'])->toBe(1);
    });

    $late = deadlineTick();
    $first = $series->refresh();

    expect($raced)->toBeTrue()
        ->and($late['confirmed'])->toBe(0)
        ->and($first->status)->toBe(SeriesStatus::Resolved)
        ->and(RatingChange::query()->count())->toBe(0);
});

test('a stale copy of an overdue series is moved to the queue once', function () {
    [, $series] = deadlineDuel();
    $this->travel(2)->hours();
    $stale = SeriesMatch::query()->findOrFail($series->id);
    $service = app(SeriesService::class);

    expect($service->markOverdue($stale))->toBeTrue();
    $overdue = $series->refresh()->overdue_at;
    $this->travel(1)->minutes();

    expect($service->markOverdue($stale))->toBeFalse()
        ->and($series->refresh()->overdue_at->equalTo($overdue))->toBeTrue();
});

/* ---------- Per-tournament deadlines ------------------------------------------------------------------------ */

test("a tournament's own deadlines beat the config", function () {
    [, $series, $a] = deadlineDuel(['noshow_minutes' => 10, 'report_hours' => 1, 'response_minutes' => 5]);

    expect($series->deadlines)->toBe(['noshow_minutes' => 10, 'report_hours' => 1, 'response_minutes' => 5])
        ->and($series->noshowMinutes())->toBe(10)
        ->and($series->reportDueAt()->equalTo($series->start_at->copy()->addHour()))->toBeTrue();

    $this->travel(10)->minutes();
    app(SeriesService::class)->reportNoShow($series, $a);
    $this->travel(5)->minutes();

    expect(deadlineTick()['forfeited'])->toBe(1);
});

test("a chess tournament's check-in window beats the config and is pinned on each game", function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $tournament->forceFill(['checkin_minutes' => 2])->save();
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole();
    ChessGame::query()->where('tournament_match_id', $match->id)->delete();
    app(TournamentRunner::class)->sync($tournament->refresh());
    $game = ChessGame::query()->where('tournament_match_id', $match->id)->where('status', ChessGameStatus::Active)->sole();

    expect($game->first_move_seconds)->toBe(120)
        ->and($game->deadline_ms - $game->turn_started_ms)->toBe(120_000);

    // An edit now reaches only games started later: Black's first-move window stays the pinned one.
    $tournament->forceFill(['checkin_minutes' => 9])->save();
    $game = app(ChessGameService::class)->move($game, $game->white, 'e2e4');

    expect($game->deadline_ms - $game->turn_started_ms)->toBe(120_000);
});

test('an edit after the start leaves running series untouched, reaches the next pairing, and is logged', function () {
    [$tournament, $series, $a] = deadlineDuel();
    $organizer = deadlineAdmin();

    $changed = app(TournamentEditor::class)->update($tournament, $organizer, ['response_minutes' => 5, 'report_hours' => 1]);
    $entry = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->latest('id')->firstOrFail();

    expect($changed)->toEqualCanonicalizing(['response_minutes', 'report_hours'])
        ->and($tournament->refresh()->response_minutes)->toBe(5)
        ->and($series->refresh()->deadlines)->toBe(['noshow_minutes' => 15, 'report_hours' => 2, 'response_minutes' => 30])
        ->and($entry->action)->toBe('edited')
        ->and($entry->reason)->toBe(TournamentEditor::DEADLINES_AHEAD)
        ->and($entry->details)->toBe(['response_minutes' => [null, 5], 'report_hours' => [null, 1]]);

    $this->travel(20)->minutes();
    $series = deadlineReport($series, $a);
    $this->travel(6)->minutes();
    expect(deadlineTick()['confirmed'])->toBe(0);

    // An admin voids the series: its replay is paired now and gets the new deadlines.
    app(SeriesService::class)->decide($series, deadlineAdmin(), ['type' => 'void'], 'The lobby crashed.');
    $replay = SeriesMatch::query()->where('tournament_match_id', $series->tournament_match_id)->where('id', '!=', $series->id)->sole();

    expect($replay->deadlines)->toBe(['noshow_minutes' => 15, 'report_hours' => 1, 'response_minutes' => 5]);
});

test('deadlines outside their range are refused', function () {
    [$tournament] = deadlineDuel();

    expect(fn () => app(TournamentEditor::class)->update($tournament, deadlineAdmin(), ['response_minutes' => 1]))
        ->toThrow(TournamentRuleViolation::class, 'Keep each deadline within its range');
});

test('the edit page saves a deadline and clears it back to the default', function () {
    [$tournament] = deadlineDuel();
    $admin = deadlineAdmin();

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('Deadlines')
        ->set('responseMinutes', '4')->call('save')->assertHasErrors('responseMinutes')
        ->set('responseMinutes', '45')->call('save')->assertHasNoErrors();

    expect($tournament->refresh()->response_minutes)->toBe(45);

    $this->travel(3)->seconds();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('responseMinutes', '45')->set('responseMinutes', '')->call('save')->assertHasNoErrors();

    expect($tournament->refresh()->response_minutes)->toBeNull();
});

/* ---------- Heartbeat ----------------------------------------------------------------------------------------- */

test('a stale or missing heartbeat shows the warning on the tournaments page, a fresh one does not', function () {
    $admin = deadlineAdmin();
    $page = fn () => Livewire::actingAs($admin)->test('pages::admin.tournaments');

    expect(TournamentScheduler::health())->toBe(['last_run_at' => null, 'stale' => true]);
    $page()->assertSee('data-test="scheduler-stale"', false)->assertSee('It has not run yet');

    $this->artisan('tournaments:tick')->assertSuccessful();

    expect(TournamentScheduler::health()['stale'])->toBeFalse()
        ->and(TournamentScheduler::health()['last_run_at']->timestamp)->toBe(now()->timestamp);
    $page()->assertDontSee('data-test="scheduler-stale"', false);

    $this->travel(5)->minutes();
    $page()->assertDontSee('data-test="scheduler-stale"', false);

    $this->travel(1)->minutes();
    $page()->assertSee('data-test="scheduler-stale"', false)->assertSee('Its last run was');
});
