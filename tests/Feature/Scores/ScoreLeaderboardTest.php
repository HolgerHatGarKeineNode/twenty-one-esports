<?php

/*
| Score games (plan "AoE2 und Trackmania", P4): a leaderboard tournament of
| the score demo game from the window to the payout. Lower wins a time
| trial, higher wins a highscore, a tie goes to the earlier record, a record
| outside the window never counts, and nobody without a valid value is
| placed or paid. ScoreDemoOn switches the demo on.
*/

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScorePoints;
use App\Support\Scores\ScoreRuns;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Tests\Support\ScoreDemoOn;

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
});

test('a time trial ranks the lowest time first, a tie by the earlier record, and ignores records outside the window', function () {
    [$tournament, [$a, $b, $c, $d]] = runningScoreBoard(4);
    $start = $tournament->starts_at->toImmutable();

    $this->fake->record($a->id, 'demo-1', 61_000, $start->addHours(2))
        ->record($b->id, 'demo-1', 59_500, $start->addHours(5))
        ->record($c->id, 'demo-1', 59_500, $start->addHours(3))
        // Faster, but before the window opened and after it closed: neither counts.
        ->record($d->id, 'demo-1', 40_000, $start->subMinute())
        ->record($a->id, 'demo-1', 30_000, $start->addDays(7))
        // Another course never counts either.
        ->record($d->id, 'demo-2', 10_000, $start->addHour());

    expect(app(ScoreLeaderboards::class)->snapshot($tournament))->toBe(['stored' => 3, 'failed' => 0]);

    $rows = app(ScoreRuns::class)->standings($tournament);

    expect(array_map(fn ($row) => [$row->participant->user_id, $row->place, $row->value], $rows))->toBe([
        [$c->id, 1, 59_500],
        [$b->id, 2, 59_500],
        [$a->id, 3, 61_000],
        [$d->id, null, null],
    ]);
});

test('a highscore ranks the highest score first', function () {
    [$tournament, [$a, $b]] = runningScoreBoard(2, 'highscore');
    $start = $tournament->starts_at->toImmutable();

    $this->fake->record($a->id, 'demo-1', 12_000, $start->addHour())->record($b->id, 'demo-1', 15_500, $start->addHours(2));
    app(ScoreLeaderboards::class)->snapshot($tournament);

    expect(array_map(fn ($row) => [$row->participant->user_id, $row->value], app(ScoreRuns::class)->standings($tournament)))
        ->toBe([[$b->id, 15_500], [$a->id, 12_000]]);
});

test('a later best that replaces the window\'s best at the source never hides it', function () {
    [$tournament, [$a, $b]] = runningScoreBoard(2);
    $start = $tournament->starts_at->toImmutable();

    $this->fake->record($a->id, 'demo-1', 55_000, $start->addDays(6));
    app(ScoreLeaderboards::class)->snapshot($tournament);

    // The source now only knows a newer best, set after the window: the stored one inside it stays.
    $this->fake = ScoreDemoOn::play()->record($a->id, 'demo-1', 50_000, $start->addDays(8));
    $this->travelTo($start->addDays(9));
    app(ScoreLeaderboards::class)->snapshot($tournament);

    expect(ScoreRun::query()->where('user_id', $a->id)->pluck('value')->all())->toBe([55_000])
        ->and(app(ScoreRuns::class)->standings($tournament)[0]->value)->toBe(55_000);
});

test('a source that cannot be asked changes nothing and is no "no record"', function () {
    [$tournament, [$a]] = runningScoreBoard(2);
    $this->fake->record($a->id, 'demo-1', 55_000, $tournament->starts_at->addHour());
    app(ScoreLeaderboards::class)->snapshot($tournament);

    $this->fake->down();

    // Down once, not asked again for the second entry in the same snapshot.
    expect(app(ScoreLeaderboards::class)->snapshot($tournament))->toBe(['stored' => 0, 'failed' => 1])
        ->and(app(ScoreRuns::class)->standings($tournament)[0]->value)->toBe(55_000);
});

test('a manual submission counts once an admin verified it, never while it waits or after a rejection', function () {
    [$tournament, [$a, $b]] = runningScoreBoard(2);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $manual = app(ManualSubmissions::class);

    $run = $manual->submit($tournament, $a, '1:02.345', now()->subHour(), 'https://example.org/replay/1');
    $rejected = $manual->submit($tournament, $b, '0:58.000', now()->subHour(), 'https://example.org/replay/2');

    expect($run->value)->toBe(62_345)
        ->and(app(ScoreRuns::class)->standings($tournament)[0]->place)->toBeNull();

    $manual->approve($run, $admin);
    $manual->reject($rejected, $admin, 'The replay shows another track.');

    $rows = app(ScoreRuns::class)->standings($tournament);

    expect([$rows[0]->participant->user_id, $rows[0]->value, $rows[1]->place])->toBe([$a->id, 62_345, null])
        ->and(fn () => $manual->approve($rejected, $admin))->toThrow(TournamentRuleViolation::class);
});

test('a manual submission is refused outside the window, without a proof link, from outside the leaderboard and twice', function () {
    [$tournament, [$a]] = runningScoreBoard(2);
    $manual = app(ManualSubmissions::class);
    $refused = function (Closure $submit): ?string {
        try {
            $submit();
        } catch (TournamentRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };

    expect($refused(fn () => $manual->submit($tournament, $a, '1:00.000', $tournament->starts_at->subMinute(), 'https://example.org/r')))->toBe('achieved_at')
        ->and($refused(fn () => $manual->submit($tournament, $a, '1:00.000', now()->addMinute(), 'https://example.org/r')))->toBe('achieved_at')
        ->and($refused(fn () => $manual->submit($tournament, $a, '1:00.000', now(), 'http://example.org/r')))->toBe('proof_url')
        ->and($refused(fn () => $manual->submit($tournament, $a, 'fast', now(), 'https://example.org/r')))->toBe('value')
        ->and($refused(fn () => $manual->submit($tournament, User::factory()->create(), '1:00.000', now(), 'https://example.org/r')))->toBe('not_entered');

    $manual->submit($tournament, $a, '1:00.000', now(), 'https://example.org/r');

    expect($refused(fn () => $manual->submit($tournament, $a, '1:00.000', now(), 'https://example.org/r')))->toBe('duplicate');

    $this->travelTo($tournament->starts_at->addDays(7)->addMinutes(61));

    expect($refused(fn () => $manual->submit($tournament, $a, '1:00.000', $tournament->starts_at->addHour(), 'https://example.org/r')))->toBe('closed');
});

test('a director corrects a value with a reason, takes an entry off, but never touches their own', function () {
    [$tournament, [$a, $b, $c]] = runningScoreBoard(3);
    $start = $tournament->starts_at->toImmutable();
    $director = $tournament->creator;
    $this->fake->record($a->id, 'demo-1', 50_000, $start->addHour())->record($b->id, 'demo-1', 52_000, $start->addHour());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    $leaderboards = app(ScoreLeaderboards::class);

    $leaderboards->correct($tournament, $director, $a->id, null, 'Slow-motion run, confirmed by the replay.');
    $leaderboards->correct($tournament, $director, $c->id, 70_000, 'Time from the stream, the source missed it.');

    expect(array_map(fn ($row) => [$row->participant->user_id, $row->value, $row->source], app(ScoreRuns::class)->standings($tournament)))->toBe([
        [$b->id, 52_000, 'fake'],
        [$c->id, 70_000, 'director'],
        [$a->id, null, 'director'],
    ])
        ->and(ScoreRun::query()->where('source', 'director')->pluck('note')->all())->toBe(['Slow-motion run, confirmed by the replay.', 'Time from the stream, the source missed it.'])
        ->and(fn () => $leaderboards->correct($tournament, $director, $b->id, 1, ''))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $leaderboards->correct($tournament, $b, $c->id, 1, 'Not a director.'))->toThrow(TournamentRuleViolation::class);

    $tournament->directors()->attach($b->id, ['added_by_id' => $director->id]);

    expect(fn () => $leaderboards->correct($tournament, $b, $b->id, 1, 'My own value.'))->toThrow(TournamentRuleViolation::class);
});

test('the leaderboard runs through placement, payout and points, and starts no series, no game and no rating', function () {
    [$tournament, [$a, $b, $c, $d]] = runningScoreBoard(4, 'time-trial', ['prize_split' => [50, 30, 20]]);
    $start = $tournament->starts_at->toImmutable();
    $this->fake->record($a->id, 'demo-1', 63_000, $start->addHour())
        ->record($b->id, 'demo-1', 61_000, $start->addHour())
        ->record($c->id, 'demo-1', 62_000, $start->addHour());
    $leaderboards = app(ScoreLeaderboards::class);

    expect(SeriesMatch::query()->count())->toBe(0)
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(fn () => $leaderboards->finalize($tournament, $tournament->creator))->toThrow(TournamentRuleViolation::class);

    // The window closed, the review time not yet: the league waits; a director may end it now.
    $this->travelTo($start->addDays(7)->addHour());
    expect($leaderboards->tick())->toBe(['finalized' => 0, 'snapshots' => 3]);

    $this->travelTo($start->addDays(8)->addHour());
    expect($leaderboards->tick()['finalized'])->toBe(1);

    $tournament->refresh();
    $places = app(TournamentPlacements::class)->of($tournament);
    $participantOf = TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('id', 'user_id');
    $payout = app(PayoutPlan::class)->compute($tournament, 10_000);

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($places)->toBe([
            ['place' => 1, 'participants' => [$participantOf[$b->id]]],
            ['place' => 2, 'participants' => [$participantOf[$c->id]]],
            ['place' => 3, 'participants' => [$participantOf[$a->id]]],
        ])
        ->and(array_map(fn (array $row): array => [$row['user']->id, $row['amount']], $payout['rows']))->toBe([[$b->id, 5000], [$c->id, 3000], [$a->id, 2000]])
        ->and(app(ScorePoints::class)->ladder(app(GameRegistry::class)->get('score-demo'), 'time-trial'))->toBe([$b->id => 25, $c->id => 18, $a->id => 15])
        // Nothing of the series or chess flow ran, and nothing was rated.
        ->and(SeriesMatch::query()->count())->toBe(0)
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($tournament->format)->toBe(TournamentFormat::Leaderboard);
});

test('a leaderboard has no match result for a director to enter', function () {
    [$tournament] = runningScoreBoard(2);
    $match = $tournament->matches()->where('bracket', 'board')->firstOrFail();

    expect(fn () => app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => '1-0']))
        ->toThrow(TournamentRuleViolation::class);
});

test('the review waits for the submissions an admin has not decided yet', function () {
    [$tournament, [$a]] = runningScoreBoard(2);
    app(ManualSubmissions::class)->submit($tournament, $a, '1:00.000', now(), 'https://example.org/r');
    $this->travelTo($tournament->starts_at->addDays(9));

    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);
});

test('an admin never approves a submission twice, nor two admins one at once', function () {
    [$tournament, [$a]] = runningScoreBoard(2);
    $this->travelTo($tournament->starts_at->addHour());
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $run = app(ManualSubmissions::class)->submit($tournament, $a, '1:00.000', now(), 'https://example.org/p');
    $stale = ScoreRun::query()->find($run->id);

    app(ManualSubmissions::class)->approve($run, $admin);

    // A second admin who loaded the submission before the first decided finds it decided.
    expect(fn () => app(ManualSubmissions::class)->reject($stale, $admin, 'Too late.'))->toThrow(TournamentRuleViolation::class);
});
