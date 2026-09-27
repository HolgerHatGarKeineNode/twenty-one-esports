<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\RoundTimes;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Honest duration of online tournaments (P18, user decision 2026-09-27)
|--------------------------------------------------------------------------
|
| An online tournament of a minute game is played on one day: its series
| run on the round clock (every deadline from the match's own start), a
| knockout match starts as soon as both its sides are known, a round-robin
| match once both its sides are done with the earlier rounds, and Swiss
| still pairs round by round. Rounds are measured. The tournament page
| shows a start and an open end, never a fixed one.
|
*/

beforeEach(function () {
    Queue::fake();
    // Whole seconds: the database stores them, and the round clock is compared to them.
    $this->freezeSecond();
    config(['esports.tournaments.round_clock' => ['noshow_minutes' => 15, 'grace_minutes' => 5, 'response_minutes' => 10]]);
});

/**
 * A running online players-mode tournament of `$n` solo players, bracket
 * stored and synced (seed 1 strongest).
 *
 * @param  array<string, mixed>  $attributes
 */
function honestTournament(TournamentFormat $format, int $n, string $game = 'rocket-league', string $mode = '1v1', array $attributes = []): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => $mode, 'format' => $format, 'capacity' => $n,
        'options' => FormatOptions::defaults(GameProfile::for($game, $mode))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'honest-'.fake()->unique()->numberBetween(1, 1_000_000),
        ...$attributes,
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The series of a tournament match, or null before it started. */
function honestSeries(TournamentMatch $match): ?SeriesMatch
{
    return SeriesMatch::query()->where('tournament_match_id', $match->id)->latest('id')->first();
}

/** The challenger wins its series cleanly and the other side confirms: the match is done. */
function honestFinish(TournamentMatch $match): void
{
    $series = honestSeries($match) ?? throw new RuntimeException("Match {$match->key} has no series.");
    $service = app(SeriesService::class);
    $winner = User::query()->findOrFail($series->rosterSide('challenger')[0]);
    $loser = User::query()->findOrFail($series->rosterSide('challenged')[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, 3, 1, null);
    }

    $service->report($series->refresh(), $winner, []);
    $service->respond($series->refresh(), $loser, 'confirmed', '', []);
}

function honestMatch(Tournament $tournament, string $key): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', $key)->with('slots', 'round')->sole();
}

/* ---------- The round clock ------------------------------------------------------------------------------- */

test('an online series runs on the round clock: no-show 15, result 15 + longest play + 5 after its start, answer 10', function () {
    // RL 1v1, one final (Bo5): 15 + (5 + 5 × 8 × 1.25) + 5 = 75 minutes after the start.
    $tournament = honestTournament(TournamentFormat::SingleElimination, 2);
    $series = honestSeries(honestMatch($tournament, 'm1-1'));

    expect($series->best_of)->toBe(5)
        ->and($series->deadlines)->toBe(['noshow_minutes' => 15, 'report_minutes' => 75, 'response_minutes' => 10])
        ->and($series->reportDueAt()->equalTo($series->start_at->copy()->addMinutes(75)))->toBeTrue();

    $this->travel(74)->minutes();
    expect(app(TournamentScheduler::class)->tick()['overdue'])->toBe(0);

    $this->travel(1)->minutes();
    expect(app(TournamentScheduler::class)->tick()['overdue'])->toBe(1)
        ->and($series->refresh()->overdue_at)->not->toBeNull();
});

test('the round clock derives the result deadline from each series\' own best-of (EA Sports FC, Bo3 final)', function () {
    // 15 + (5 + 3 × 15 × 1.3) + 5 = 83.5, rounded up.
    $series = honestSeries(honestMatch(honestTournament(TournamentFormat::SingleElimination, 2, 'ea-sports-fc-26'), 'm1-1'));

    expect($series->best_of)->toBe(3)
        ->and($series->deadlines)->toBe(['noshow_minutes' => 15, 'report_minutes' => 84, 'response_minutes' => 10]);
});

test('the tournament\'s own deadlines beat the round clock; its own no-show wait moves the derived result deadline', function () {
    $own = honestSeries(honestMatch(honestTournament(TournamentFormat::SingleElimination, 2, attributes: ['noshow_minutes' => 20, 'report_hours' => 1, 'response_minutes' => 5]), 'm1-1'));
    $noshowOnly = honestSeries(honestMatch(honestTournament(TournamentFormat::SingleElimination, 2, attributes: ['noshow_minutes' => 20]), 'm1-1'));

    expect($own->deadlines)->toBe(['noshow_minutes' => 20, 'report_hours' => 1, 'response_minutes' => 5])
        ->and($own->reportDueAt()->equalTo($own->start_at->copy()->addHour()))->toBeTrue()
        ->and($noshowOnly->deadlines)->toBe(['noshow_minutes' => 20, 'report_minutes' => 80, 'response_minutes' => 10]);
});

test('on site and in daily chess the long defaults stay', function () {
    config(['esports.tournaments.report_hours' => 2, 'esports.tournaments.response_minutes' => 30, 'esports.series.noshow_minutes' => 15]);
    $series = honestSeries(honestMatch(honestTournament(TournamentFormat::SingleElimination, 2, attributes: ['on_site' => true, 'stations' => 2]), 'm1-1'));

    expect($series->deadlines)->toBe(['noshow_minutes' => 15, 'report_hours' => 2, 'response_minutes' => 30]);
});

/* ---------- Who starts when ------------------------------------------------------------------------------- */

test('a knockout match starts when its two predecessors are done, while another match of their round still runs', function () {
    $tournament = honestTournament(TournamentFormat::SingleElimination, 8);

    $this->travel(20)->minutes();
    honestFinish(honestMatch($tournament, 'm1-1'));
    expect(honestSeries(honestMatch($tournament, 'm2-1')))->toBeNull();

    $this->travel(10)->minutes();
    honestFinish(honestMatch($tournament, 'm1-2'));

    $semifinal = honestSeries(honestMatch($tournament, 'm2-1'));

    expect($semifinal)->not->toBeNull()
        ->and(honestSeries(honestMatch($tournament, 'm1-3'))->status)->toBe(SeriesStatus::Accepted)
        ->and(honestSeries(honestMatch($tournament, 'm2-2')))->toBeNull()
        // Its round clock runs from its own start, not from the round's.
        ->and($semifinal->start_at->equalTo(now()))->toBeTrue()
        ->and($semifinal->reportDueAt()->equalTo(now()->addMinutes(55)))->toBeTrue();
});

test('Swiss does not pull forward: the next round is paired only when the whole round is done', function () {
    $tournament = honestTournament(TournamentFormat::Swiss, 8);
    $first = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots', 'round')->orderBy('id')->get();

    expect($first)->toHaveCount(4);

    foreach ($first->take(3) as $match) {
        honestFinish($match);
    }

    expect(TournamentRound::query()->whereHas('stage', fn ($q) => $q->where('tournament_id', $tournament->id))->count())->toBe(1)
        ->and(SeriesMatch::query()->whereNotNull('tournament_match_id')->count())->toBe(4);

    honestFinish($first[3]);

    expect(TournamentRound::query()->whereHas('stage', fn ($q) => $q->where('tournament_id', $tournament->id))->count())->toBe(2)
        ->and(SeriesMatch::query()->whereNotNull('tournament_match_id')->count())->toBe(8);
});

test('a round-robin match starts once both its sides are done with the earlier rounds, not before', function () {
    $tournament = honestTournament(TournamentFormat::RoundRobin, 4);
    $roundOne = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('round', fn ($q) => $q->where('number', 1))->with('slots', 'round')->get();
    $later = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('round', fn ($q) => $q->where('number', '>', 1))->get();

    // Only round 1 is played at first: nobody holds two series at once.
    expect($roundOne)->toHaveCount(2)
        ->and($roundOne->every(fn (TournamentMatch $match): bool => honestSeries($match) !== null))->toBeTrue()
        ->and($later->every(fn (TournamentMatch $match): bool => honestSeries($match) === null))->toBeTrue();

    // A round-2 match meets one side of each round-1 match.
    $roundTwo = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('round', fn ($q) => $q->where('number', 2))->with('slots')->orderBy('id')->first();
    [$x, $y] = $roundTwo->slots->pluck('tournament_participant_id')->all();
    $ofX = $roundOne->first(fn (TournamentMatch $match): bool => $match->slots->contains('tournament_participant_id', $x));
    $ofY = $roundOne->first(fn (TournamentMatch $match): bool => $match->slots->contains('tournament_participant_id', $y));

    expect($ofX->id)->not->toBe($ofY->id);

    // Only one side is done: the match waits for the other.
    honestFinish($ofX);
    expect(honestSeries($roundTwo))->toBeNull();

    honestFinish($ofY);
    expect(honestSeries($roundTwo))->not->toBeNull();
});

/* ---------- The measurement ------------------------------------------------------------------------------- */

test('a round\'s start and close are stored, shown on the edit page against the estimate, and aggregated', function () {
    $tournament = honestTournament(TournamentFormat::SingleElimination, 2, attributes: ['created_by_id' => User::factory()->create()->id]);
    $round = TournamentRound::query()->whereHas('stage', fn ($q) => $q->where('tournament_id', $tournament->id))->sole();

    expect($round->started_at->equalTo(now()))->toBeTrue()
        ->and($round->closed_at)->toBeNull();

    $this->travel(38)->minutes();
    honestFinish(honestMatch($tournament, 'm1-1'));
    $round->refresh();

    // A Bo5 final: 5 + 5 × 8 + 5 overhead = 50 min estimated.
    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($round->closed_at->equalTo(now()))->toBeTrue()
        ->and(RoundTimes::forTournament($tournament))->toBe([['stage' => 1, 'number' => 1, 'minutes' => 38, 'estimate' => 50]])
        ->and(RoundTimes::median('rocket-league', '1v1'))->toBe(['minutes' => 38.0, 'rounds' => 1])
        ->and(RoundTimes::median('rocket-league', '3v3'))->toBeNull();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('admin.tournaments.edit', $tournament))->assertOk()
        ->assertSeeText('Round 1: 38 min (estimate 50 min)');
    $this->actingAs($admin)->withSession(['locale' => 'de'])->get(route('admin.tournaments.edit', $tournament))->assertOk()
        ->assertSeeText('Runde 1: 38 Min. (Schätzung 50 Min.)');
});

/* ---------- The tournament page: a start and an open end --------------------------------------------------- */

test('the tournament page shows a start and an open end, never a fixed end, in English and German', function () {
    // FC 26 1v1, 16 players, Two Stage: expected 235 min after the start, at 20:00 Berlin (18:00 UTC).
    $tournament = Tournament::factory()->signup()->create([
        'game' => 'ea-sports-fc-26', 'mode' => '1v1', 'format' => TournamentFormat::TwoStage, 'capacity' => 16,
        'options' => FormatOptions::defaults(GameProfile::for('ea-sports-fc-26', '1v1'))->toArray(),
        'starts_at' => now()->addDays(3)->setTimezone('Europe/Berlin')->setTime(20, 0)->utc(),
        'signup_closes_at' => now()->addDays(2),
    ]);
    $expected = $tournament->starts_at->copy()->addMinutes(235)->timezone('Europe/Berlin')->format('G:i');

    expect($expected)->toBe('23:55')
        ->and($tournament->expectedEnd()['latest']->diffInMinutes($tournament->starts_at, true))->toEqual(394.0);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeText("Open end, expected around {$expected}")
        ->assertDontSeeText('Planned duration');
    $this->get(route('tournaments.index'))->assertOk()
        ->assertSeeText("Open end, expected around {$expected}");
    $this->withSession(['locale' => 'de'])->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeText("Ende offen, voraussichtlich gegen {$expected}")
        ->assertDontSeeText('Geplante Dauer');
});

test('on site the page keeps its planned duration and has no open end', function () {
    $tournament = Tournament::factory()->signup()->create(['on_site' => true, 'stations' => 6]);

    expect($tournament->expectedEnd())->toBeNull();

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeText('Planned duration')
        ->assertDontSeeText('Open end');
});

/* ---------- The chooser ---------------------------------------------------------------------------------- */

test('the chooser computes the honest range with its times and warnings, and the page shows it with the round-clock defaults', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // Islands render only in the page itself (the browser test covers their updates); here the state behind them.
    $page = Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('startsAt', now()->addWeek()->timezone('Europe/Berlin')->format('Y-m-d').'T20:00')
        ->call('pickGame', 'ea-sports-fc-26/1v1')
        ->set('players', '16')
        ->call('select', 'two-stage');
    $range = $page->instance()->durationRange;
    $times = $page->instance()->expectedTimes();

    expect([$range->planned, $range->typical, $range->latest])->toBe([175.0, 235.0, 394.0])
        ->and($times['typical']->timezone('Europe/Berlin')->format('G:i'))->toBe('23:55')
        ->and($times['latest']->timezone('Europe/Berlin')->format('G:i'))->toBe('2:34')
        ->and($times['warnings'])->toBe([]);

    // 21:00: the typical end passes midnight; a round robin of 16: the worst case passes 10 hours.
    $page->set('startsAt', now()->addWeek()->timezone('Europe/Berlin')->format('Y-m-d').'T21:00');
    expect($page->instance()->expectedTimes()['warnings'])->toBe(['after-midnight']);

    $page->set('startsAt', now()->addWeek()->timezone('Europe/Berlin')->format('Y-m-d').'T12:00')->call('select', 'round-robin');
    expect($page->instance()->durationRange->latest)->toBeGreaterThan(600.0)
        ->and($page->instance()->expectedTimes()['warnings'])->toBe(['too-long']);

    // The page: the range under the chosen format, the round clock as the deadlines' defaults.
    $this->actingAs($admin)->get(route('admin.tournaments.create'))->assertOk()
        ->assertSeeHtml('data-test="duration-range"')
        ->assertSeeText('Start 19:00 · expected end about')
        ->assertSeeHtml('data-test="round-clock"');
});
