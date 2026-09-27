<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\NotificationKind;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Rating\RatingService;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Control over a running tournament (P18, slice 4)
|--------------------------------------------------------------------------
|
| Admins and the tournament's organizer set or correct results (the
| bracket re-flows: later unplayed matches are voided and paired again,
| played ones are held), disqualify an entry, pause and resume (deadlines
| move by the pause), restart a round, call the tournament off and write to
| all players. A player or a foreign organizer is refused, a double click
| changes nothing twice, every step is logged and broadcast.
|
*/

beforeEach(function () {
    Queue::fake();
    Event::fake([TournamentChanged::class]);
    config(['esports.series.noshow_minutes' => 15, 'esports.tournaments.report_hours' => 2, 'esports.tournaments.response_minutes' => 30]);
});

function ctlAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/**
 * A running players-mode RL 1v1 knockout of `$n` solo players (casual: no
 * season), on site so the config deadlines apply; its first series paired.
 *
 * @param  array<string, mixed>  $attributes
 */
function ctlKnockout(int $n = 4, array $attributes = [], bool $published = false): Tournament
{
    $profile = GameProfile::for('rocket-league', '1v1');
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => $n,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], $profile)->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => $published ? TournamentStatus::Draft : TournamentStatus::Running,
        'slug' => $published ? null : 'ctl-'.fake()->unique()->numberBetween(1, 1_000_000),
        'on_site' => true, 'stations' => 4, 'created_by_id' => organizer()->id,
        ...$attributes,
    ]);

    if ($published) {
        // Its 31923 signed by the league, as a publish signs it; then drawn and running.
        $tournament = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addHour());
        $tournament->forceFill(['status' => TournamentStatus::Running])->save();
    }

    foreach (range(1, $n) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The match of `$round` at `$position` (1-based) in the main bracket. */
function ctlMatch(Tournament $tournament, int $round, int $position = 1): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->where('number', $round))->orderBy('position')->with(['slots.participant', 'seriesMatch'])
        ->get()->values()[$position - 1];
}

/** The current series of a match. */
function ctlSeries(TournamentMatch $match): SeriesMatch
{
    return SeriesMatch::query()->where('tournament_match_id', $match->id)->orderByDesc('tournament_attempt')->firstOrFail();
}

/** The player of `$slot` of a match. */
function ctlPlayer(TournamentMatch $match, int $slot): User
{
    $match->load('slots.participant');

    return User::query()->findOrFail($match->slots[$slot]->participant->user_id);
}

/** `$slot` of the match wins its series, reported and confirmed (casual: nothing to sign). */
function ctlWin(TournamentMatch $match, int $slot): SeriesMatch
{
    $series = ctlSeries($match);
    $winner = ctlPlayer($match, $slot);
    $loser = ctlPlayer($match, 1 - $slot);
    $service = app(SeriesService::class);
    $challenger = $series->captainSideOf($winner) === 'challenger';

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
    }

    $service->report($series, $winner, []);
    $service->respond($series->refresh(), $loser, 'confirmed', '', []);

    return $series->refresh();
}

/** A clean series win for `$slot` as the control's input: as many games as the series needs. */
function ctlSweep(TournamentMatch $match, int $slot): array
{
    return ['winners' => array_fill(0, intdiv(ctlSeries($match)->best_of, 2) + 1, $slot)];
}

function ctl(): TournamentControl
{
    return app(TournamentControl::class);
}

/* ---------- 1. Results and the re-flow ------------------------------------------------------------------ */

test('a correction in round 1 after round 2 was paired voids the paired series and pairs the new winner, unrated', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $final = ctlMatch($tournament, 2);
    $paired = ctlSeries($final);
    $ratings = RatingChange::query()->count();
    $loser = ctlMatch($tournament, 1, 1)->slots[1]->tournament_participant_id;

    expect($paired->status)->toBe(SeriesStatus::Accepted)->and($ratings)->toBeGreaterThan(0);

    $changed = ctl()->setResult($tournament, $admin, ctlMatch($tournament, 1, 1)->id, ctlSweep(ctlMatch($tournament, 1, 1), 1), 'The report named the wrong winner');

    $first = ctlMatch($tournament, 1, 1);
    $final = ctlMatch($tournament, 2);
    $replay = ctlSeries($final);
    $log = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole();

    expect($changed)->toBeTrue()
        ->and($first->result)->toMatchArray(['winner' => 1, 'by' => 'control', 'unrated' => true, 'was' => '2 : 0', 'label' => '0 : 2'])
        ->and($first->result['corrected']['user_id'])->toBe($admin->id)
        // The paired final was voided by the league and is played again by the corrected winner.
        ->and($paired->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and($paired->resolution)->toBe(SeriesResolution::Void)
        ->and($final->replaced_through)->toBe($paired->id)
        ->and($replay->id)->not->toBe($paired->id)
        ->and($replay->tournament_attempt)->toBe(2)
        ->and($replay->status)->toBe(SeriesStatus::Accepted)
        ->and($final->slots[0]->tournament_participant_id)->toBe($loser)
        ->and(in_array(ctlPlayer($final, 0)->id, $replay->rosterSide('challenger'), true))->toBeTrue()
        // No rating moved: the old change stays, the correction adds none.
        ->and(RatingChange::query()->count())->toBe($ratings)
        ->and($log->action)->toBe('result')
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->reason)->toBe('The report named the wrong winner')
        ->and($log->details['result'])->toBe(['2 : 0', '0 : 2'])
        ->and($log->details['voided'][1])->toHaveCount(1)
        ->and(TournamentResultEntry::query()->where('tournament_id', $tournament->id)->sole()->replaced['label'])->toBe('2 : 0');

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->tournamentId === $tournament->id && $event->reason === 'result');
});

test('a correction after round 2 was played holds the final, reopens the tournament, and a result there ends it again', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $played = ctlWin(ctlMatch($tournament, 2), 0);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    ctl()->setResult($tournament, $admin, ctlMatch($tournament, 1, 1)->id, ctlSweep(ctlMatch($tournament, 1, 1), 1), 'The report named the wrong winner');

    $final = ctlMatch($tournament, 2);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($final->result)->toBeNull()
        ->and($final->held['was']['label'])->toBe('3 : 0')
        ->and($final->status)->toBe('ready')
        // Held: no new series starts by itself, the played one stays as it was.
        ->and(SeriesMatch::query()->where('tournament_match_id', $final->id)->count())->toBe(1)
        ->and($played->refresh()->status)->toBe(SeriesStatus::Confirmed)
        ->and(TournamentModerationEntry::query()->where('action', 'result')->sole()->details['held'][1])->toHaveCount(1);

    // The organizer decides the held final.
    ctl()->setResult($tournament, $tournament->creator, $final->id, ctlSweep($final, 0), 'Replayed on stage');

    $final = ctlMatch($tournament, 2);
    $champion = app(TournamentChampion::class)->of($tournament->refresh());

    expect($final->held)->toBeNull()
        ->and($final->result)->toMatchArray(['winner' => 0, 'by' => 'control', 'was' => '3 : 0'])
        ->and($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($champion?->id)->toBe($final->slots[0]->tournament_participant_id);
});

test('a result set on a series still being played closes it as the league\'s decision, unrated; the same result again changes nothing', function () {
    $tournament = ctlKnockout(2);
    $admin = ctlAdmin();
    $match = ctlMatch($tournament, 1);
    $series = ctlSeries($match);

    expect(ctl()->setResult($tournament, $admin, $match->id, ['noshow' => 1], 'Did not show up on stage'))->toBeTrue()
        // A double click: the same result again.
        ->and(ctl()->setResult($tournament, $admin, $match->id, ['noshow' => 1], 'Did not show up on stage'))->toBeFalse();

    $series->refresh();

    expect($series->status)->toBe(SeriesStatus::Resolved)
        ->and($series->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($series->winner)->toBe('challenger')
        ->and($series->resolved_by_id)->toBe($admin->id)
        ->and($series->resolution_reason)->toBe('Did not show up on stage')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(ctlMatch($tournament, 1)->result)->toMatchArray(['winner' => 0, 'forfeit' => true, 'by' => 'control'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(TournamentModerationEntry::query()->where('action', 'result')->count())->toBe(1)
        ->and(TournamentResultEntry::query()->count())->toBe(1);
});

test('chess: a correction voids the live final game and starts the new pairing', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players, ['thirdPlace' => false]);
    $admin = ctlAdmin();
    $chess = app(ChessGameService::class);

    foreach ([1, 2] as $position) {
        $game = ChessGame::query()->where('tournament_match_id', ctlMatch($tournament, 1, $position)->id)->sole();
        $chess->resign($game, $game->black);
    }

    $final = ctlMatch($tournament, 2);
    $live = ChessGame::query()->where('tournament_match_id', $final->id)->sole();

    ctl()->setResult($tournament, $admin, ctlMatch($tournament, 1, 1)->id, ['result' => '0-1'], 'Illegal move not seen by the server');

    $final = ctlMatch($tournament, 2);
    $next = ChessGame::query()->where('tournament_match_id', $final->id)->where('id', '>', $live->id)->sole();

    expect($live->refresh()->status)->toBe(ChessGameStatus::Aborted)
        ->and($live->end_reason)->toBe(ChessEndReason::Voided)
        ->and($final->replaced_through)->toBe($live->id)
        ->and($next->status)->toBe(ChessGameStatus::Active)
        ->and([$next->white_id, $next->black_id])->toContain(ctlPlayer(ctlMatch($tournament, 1, 1), 1)->id);
});

test('Swiss, director mode: a correction of a closed round keeps the next round\'s pairings and its rating, and changes the table', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 4);
    $runner = app(TournamentRunner::class);
    $round = TournamentRunner::currentRound($tournament);

    foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->get() as $match) {
        $runner->enterResult($match, $tournament->creator, ['result' => '1-0']);
    }

    $runner->closeRound($round, $tournament->creator);
    $second = TournamentRunner::currentRound($tournament->refresh());
    $pairings = fn (): array => TournamentMatch::query()->where('tournament_round_id', $second->id)->with('slots')->orderBy('id')->get()
        ->map(fn (TournamentMatch $match): array => $match->slots->pluck('tournament_participant_id')->all())->all();
    $before = $pairings();
    $ratings = RatingChange::query()->count();
    $corrected = TournamentMatch::query()->where('tournament_round_id', $round->id)->orderBy('id')->firstOrFail();

    expect($second->number)->toBe(2)->and($ratings)->toBeGreaterThan(0);

    ctl()->setResult($tournament, ctlAdmin(), $corrected->id, ['result' => '0-1'], 'Scoresheet was signed the other way round');

    expect($pairings())->toBe($before)
        ->and($corrected->refresh()->result)->toMatchArray(['winner' => 1, 'by' => 'control', 'unrated' => true])
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotNull('held')->exists())->toBeFalse()
        ->and(RatingChange::query()->count())->toBe($ratings)
        ->and(TournamentRunner::currentRound($tournament->refresh())?->id)->toBe($second->id);
});

test('a correction before the payouts are approved moves the places they are paid for', function () {
    $tournament = ctlKnockout(2);
    $match = ctlMatch($tournament, 1);
    ctlWin($match, 0);
    $first = fn (): array => app(TournamentPlacements::class)->of($tournament->refresh())[0]['participants'];

    expect($first())->toBe([$match->slots[0]->tournament_participant_id]);

    ctl()->setResult($tournament, ctlAdmin(), $match->id, ctlSweep($match, 1), 'Wrong winner reported');

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($first())->toBe([$match->slots[1]->tournament_participant_id]);
});

test('once the payouts are approved a result can no longer be corrected', function () {
    $tournament = ctlKnockout(2);
    $admin = ctlAdmin();
    $match = ctlMatch($tournament, 1);
    ctlWin($match, 0);
    $tournament->refresh()->forceFill(['payouts_approved_at' => now(), 'payouts_approved_by_id' => $admin->id])->save();

    expect(fn () => ctl()->setResult($tournament, $admin, $match->id, ctlSweep($match, 1), 'Wrong winner'))
        ->toThrow(TournamentRuleViolation::class, 'The payouts of this tournament are approved')
        ->and(ctlMatch($tournament, 1)->result['winner'])->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('an interested organizer cannot set the result of their own match', function () {
    $tournament = ctlKnockout(2);
    $match = ctlMatch($tournament, 1);
    $organizer = ctlPlayer($match, 0);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament->forceFill(['created_by_id' => $organizer->id])->save();

    expect(fn () => ctl()->setResult($tournament, $organizer, $match->id, ctlSweep($match, 0), 'I won'))
        ->toThrow(TournamentRuleViolation::class, 'You have an interest in this match');
});

/* ---------- 2. Disqualification ------------------------------------------------------------------------- */

test('a disqualified entry loses its series under way by forfeit, unrated, and the opponent advances', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    $first = ctlMatch($tournament, 1, 1);
    $series = ctlSeries($first);
    $out = $first->slots[0]->participant;

    expect(ctl()->disqualify($tournament, $admin, $out->id, 'Played on a borrowed account'))->toBeTrue()
        ->and(ctl()->disqualify($tournament, $admin, $out->id, 'Played on a borrowed account'))->toBeFalse();

    $first = ctlMatch($tournament, 1, 1);

    expect($out->refresh()->disqualified_at)->not->toBeNull()
        ->and($out->disqualification_reason)->toBe('Played on a borrowed account')
        ->and($first->result)->toMatchArray(['winner' => 1, 'forfeit' => true, 'decided' => 'disqualified', 'by' => 'league'])
        ->and($series->refresh()->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($series->winner)->toBe('challenged')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(ctlMatch($tournament, 2)->slots[0]->tournament_participant_id)->toBe($first->slots[1]->tournament_participant_id)
        ->and(TournamentModerationEntry::query()->where('action', 'disqualified')->count())->toBe(1);

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'disqualified');

    // Its players hear about it.
    expect(User::query()->find($out->user_id)->notifications()->where('data->kind', NotificationKind::TournamentEntryRemoved->value)->count())->toBe(1);
});

test('a disqualified entry loses a later match by forfeit once it becomes ready', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    $winner = ctlMatch($tournament, 1, 1)->slots[0]->participant;

    ctl()->disqualify($tournament, $admin, $winner->id, 'Abusive chat');
    ctlWin(ctlMatch($tournament, 1, 2), 1);

    $final = ctlMatch($tournament, 2);

    expect($final->result)->toMatchArray(['winner' => 1, 'forfeit' => true, 'decided' => 'disqualified'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

/* ---------- 3. Pause ---------------------------------------------------------------------------------------- */

test('while paused the tick applies no deadline, and on resume every deadline moves by the pause', function () {
    $this->freezeSecond();
    $tournament = ctlKnockout(2);
    $admin = ctlAdmin();
    $series = ctlSeries(ctlMatch($tournament, 1));
    $due = $series->reportDueAt();

    expect($due?->equalTo(now()->addHours(2)))->toBeTrue();

    $this->travel(30)->minutes();
    expect(ctl()->pause($tournament, $admin, 'Power cut in the hall'))->toBeTrue()
        ->and(ctl()->pause($tournament, $admin, 'Power cut in the hall'))->toBeFalse();

    // Three hours paused: the report deadline passes, the tick leaves the series alone.
    $this->travel(3)->hours();
    expect(app(TournamentScheduler::class)->tick()['overdue'])->toBe(0)
        ->and($series->refresh()->overdue_at)->toBeNull();

    expect(ctl()->resume($tournament, $admin))->toBeTrue()
        ->and(ctl()->resume($tournament, $admin))->toBeFalse();

    $series->refresh();
    expect($series->reportDueAt()?->equalTo($due->copy()->addHours(3)))->toBeTrue()
        ->and($series->nextDeadline()['at']->equalTo($due->copy()->addHours(3)))->toBeTrue();

    // 1 h 29 min after the resume the shifted deadline has not passed yet; one minute later it has.
    $this->travel(89)->minutes();
    expect(app(TournamentScheduler::class)->tick()['overdue'])->toBe(0);

    $this->travel(2)->minutes();
    expect(app(TournamentScheduler::class)->tick()['overdue'])->toBe(1)
        ->and($series->refresh()->overdue_at)->not->toBeNull()
        ->and(TournamentModerationEntry::query()->pluck('action')->all())->toBe(['paused', 'resumed']);

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'paused');
    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'resumed');
});

/*
 * The tick reads its batch first and applies each deadline after: a pause
 * that lands in between must still win (review of 723e62f, TOCTOU). Each
 * transition gets the series as read before the pause.
 */
test('a deadline transition on a series read before the pause is not applied', function (string $transition) {
    $this->freezeSecond();
    $tournament = ctlKnockout(2);
    $admin = ctlAdmin();
    $match = ctlMatch($tournament, 1);
    $series = ctlSeries($match);
    $service = app(SeriesService::class);
    // A no-show can be reported 15 min after the start; its answer, like a report's, is due 30 min later.
    $this->travel(20)->minutes();

    match ($transition) {
        'forfeitNoShow' => $service->reportNoShow($series, ctlPlayer($match, 0)),
        'autoConfirm' => (function () use ($series, $match, $service): void {
            $winner = ctlPlayer($match, 0);
            $challenger = $series->captainSideOf($winner) === 'challenger';

            foreach (range(0, intdiv($series->best_of, 2)) as $index) {
                $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
            }

            $service->report($series, $winner, []);
        })(),
        default => null,
    };

    // The tick has read this series; now the pause comes in and lasts three hours, past every original deadline.
    $this->travel(10)->minutes();
    $read = SeriesMatch::query()->with('latestReport')->findOrFail($series->id);
    ctl()->pause($tournament, $admin, 'Power cut');
    $this->travel(3)->hours();

    expect($service->{$transition}($read))->toBeFalse()
        ->and($series->refresh()->status)->toBe($transition === 'autoConfirm' ? SeriesStatus::Reported : SeriesStatus::Accepted)
        ->and($series->overdue_at)->toBeNull()
        ->and($series->resolution)->toBeNull();

    // Resumed: the deadline, moved by the pause, has not passed yet; the stale read does not apply it either.
    ctl()->resume($tournament, $admin);

    expect($service->{$transition}($read))->toBeFalse()
        ->and($series->refresh()->status)->toBe($transition === 'autoConfirm' ? SeriesStatus::Reported : SeriesStatus::Accepted)
        ->and($series->overdue_at)->toBeNull();
})->with(['markOverdue', 'forfeitNoShow', 'autoConfirm']);

test('startReady on a tournament read before the pause starts nothing', function () {
    $tournament = ctlKnockout();
    $stale = Tournament::query()->findOrFail($tournament->id);
    ctl()->pause($tournament, ctlAdmin());
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $final = ctlMatch($tournament, 2);

    expect($stale->isPaused())->toBeFalse()->and($final->status)->toBe('ready');

    app(TournamentMatchMaker::class)->startReady($stale);

    expect(SeriesMatch::query()->where('tournament_match_id', $final->id)->exists())->toBeFalse();
});

test('while paused no match starts; resuming starts the ready ones', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    ctl()->pause($tournament, $admin);
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $final = ctlMatch($tournament, 2);

    expect($final->status)->toBe('ready')
        ->and(SeriesMatch::query()->where('tournament_match_id', $final->id)->exists())->toBeFalse();

    ctl()->resume($tournament, $admin);

    expect(SeriesMatch::query()->where('tournament_match_id', $final->id)->sole()->status)->toBe(SeriesStatus::Accepted);
});

/* ---------- 4. Restarting a round -------------------------------------------------------------------------- */

test('restarting a round voids its unconfirmed series and pairs them again, once per click', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    $first = ctlMatch($tournament, 1, 1);
    $reported = ctlSeries($first);
    $service = app(SeriesService::class);
    $winner = ctlPlayer($first, 0);

    foreach (range(0, intdiv($reported->best_of, 2)) as $index) {
        $service->saveLiveGame($reported, $winner, $index, 3, 1, null);
    }

    $service->report($reported, $winner, []);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $round = $first->round;

    expect(ctl()->restartRound($tournament, $admin, $round->id, 0, 'Server outage during the round'))->toBeTrue()
        // The same click again: the round is restarted already.
        ->and(ctl()->restartRound($tournament, $admin, $round->id, 0, 'Server outage during the round'))->toBeFalse();

    $replay = ctlSeries(ctlMatch($tournament, 1, 1));

    expect($reported->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and($replay->tournament_attempt)->toBe(2)
        ->and($replay->status)->toBe(SeriesStatus::Accepted)
        // The decided match stays decided.
        ->and(ctlMatch($tournament, 1, 2)->result['winner'])->toBe(0)
        ->and($round->refresh()->restarts)->toBe(1)
        ->and(TournamentModerationEntry::query()->where('action', 'round_restarted')->count())->toBe(1);

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'round');
});

test('a round is not restarted in director mode or when nothing in it is undecided', function () {
    $director = runningChess(TournamentFormat::SingleElimination, 4);
    $admin = ctlAdmin();

    expect(fn () => ctl()->restartRound($director, $admin, ctlMatch($director, 1)->tournament_round_id, 0, 'Try it'))
        ->toThrow(TournamentRuleViolation::class, 'In director mode');

    $tournament = ctlKnockout(2);
    ctlWin(ctlMatch($tournament, 1), 0);
    $tournament->refresh()->forceFill(['status' => TournamentStatus::Running])->save();

    expect(fn () => ctl()->restartRound($tournament, $admin, ctlMatch($tournament, 1)->tournament_round_id, 0, 'Try it'))
        ->toThrow(TournamentRuleViolation::class, 'nothing to restart');
});

test('restarting the round of a held match plays it again', function () {
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    ctlWin(ctlMatch($tournament, 1, 1), 0);
    ctlWin(ctlMatch($tournament, 1, 2), 0);
    $played = ctlWin(ctlMatch($tournament, 2), 0);
    ctl()->setResult($tournament, $admin, ctlMatch($tournament, 1, 1)->id, ctlSweep(ctlMatch($tournament, 1, 1), 1), 'Wrong winner reported');
    $final = ctlMatch($tournament, 2);

    ctl()->restartRound($tournament, $admin, $final->tournament_round_id, 0, 'Play the final again');

    $replay = ctlSeries(ctlMatch($tournament, 2));

    expect(ctlMatch($tournament, 2)->held)->toBeNull()
        ->and($replay->id)->not->toBe($played->id)
        ->and($replay->tournament_attempt)->toBe(2)
        ->and($played->refresh()->status)->toBe(SeriesStatus::Confirmed);
});

/* ---------- 5. Calling it off ------------------------------------------------------------------------------ */

test('calling a tournament off voids its open series, rates nothing after and publishes a called-off version', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    $tournament = ctlKnockout(published: true);
    $first = $tournament->event_id;
    $admin = ctlAdmin();
    $open = ctlSeries(ctlMatch($tournament, 1, 1));

    expect(ctl()->abort($tournament, $admin, 'The venue closed'))->toBeTrue()
        ->and(ctl()->abort($tournament, $admin, 'The venue closed'))->toBeFalse();

    $event = $tournament->refresh()->event;
    $tags = $event->payload()['tags'];

    expect($tournament->status)->toBe(TournamentStatus::Cancelled)
        ->and($event->id)->not->toBe($first)
        ->and(NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $tournament->slug)->count())->toBe(2)
        ->and($open->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and(collect($tags)->firstWhere(0, 'title')[1])->toStartWith('Called off: ')
        ->and(collect($tags)->firstWhere(0, 'd')[1])->toBe($tournament->slug)
        ->and($event->payload()['content'])->toStartWith('This tournament was called off by the league.')
        ->and(TournamentModerationEntry::query()->where('action', 'aborted')->sole()->reason)->toBe('The venue closed');

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'cancelled');

    // Whatever still ends later moves no rating.
    $open->forceFill(['status' => SeriesStatus::Confirmed, 'resolution' => SeriesResolution::Confirmed, 'winner' => 'challenger', 'result_games' => [['winner' => 'challenger', 'challenger' => 1, 'challenged' => 0]]])->save();

    expect(app(RatingService::class)->applySeries($open->refresh()))->toBeFalse()
        ->and(RatingChange::query()->count())->toBe(0);
});

/* ---------- 6. Messages ------------------------------------------------------------------------------------ */

test('the only player writing to all players is told there is nobody else yet, not that nobody plays', function () {
    $tournament = Tournament::factory()->signup()->create(['signup_closes_at' => now()->addDay()]);
    $admin = ctlAdmin();
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $admin->id, 'name' => $admin->displayName(), 'members' => [$admin->id]]);

    expect(fn () => ctl()->message($tournament, $admin, 'Test'))->toThrow(TournamentRuleViolation::class, 'You are the only player so far');
});

test('a message reaches every player once, in the bell, and is rate limited', function () {
    config(['esports.tournaments.messages_per_hour' => 2]);
    $tournament = ctlKnockout();
    $admin = ctlAdmin();
    RateLimiter::clear('tournament-news:'.$tournament->id);

    expect(ctl()->message($tournament, $admin, 'Round 2 starts at 15:00'))->toBe(4)
        // A double click sends nothing twice.
        ->and(ctl()->message($tournament, $admin, 'Round 2 starts at 15:00'))->toBe(0)
        ->and(ctl()->message($tournament, $admin, 'Lunch break until 13:00'))->toBe(4);

    expect(fn () => ctl()->message($tournament, $admin, 'A third one'))->toThrow(TournamentRuleViolation::class, '2 times an hour');

    $player = User::query()->find(ctlMatch($tournament, 1)->slots[0]->participant->user_id);

    expect($player->notifications()->where('data->kind', NotificationKind::TournamentNews->value)->count())->toBe(2)
        ->and(TournamentModerationEntry::query()->where('action', 'messaged')->count())->toBe(2);

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'message');
});

/* ---------- Authorisation ---------------------------------------------------------------------------------- */

test('a player and a foreign organizer are refused every action', function (string $who, string $action) {
    $tournament = ctlKnockout();
    $match = ctlMatch($tournament, 1);
    $actor = $who === 'player' ? ctlPlayer($match, 0) : organizer();
    $call = match ($action) {
        'result' => fn () => ctl()->setResult($tournament, $actor, $match->id, ctlSweep($match, 0), 'Because'),
        'disqualify' => fn () => ctl()->disqualify($tournament, $actor, $match->slots[1]->tournament_participant_id, 'Because'),
        'pause' => fn () => ctl()->pause($tournament, $actor),
        'resume' => fn () => ctl()->resume($tournament, $actor),
        'restart' => fn () => ctl()->restartRound($tournament, $actor, $match->tournament_round_id, 0, 'Because'),
        'abort' => fn () => ctl()->abort($tournament, $actor, 'Because'),
        'message' => fn () => ctl()->message($tournament, $actor, 'Hello all'),
    };

    expect($call)->toThrow(TournamentRuleViolation::class, 'Only the organizer of this tournament or an admin')
        ->and(TournamentModerationEntry::query()->count())->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($tournament->paused_at)->toBeNull();
})->with(['player', 'foreign organizer'])->with(['result', 'disqualify', 'pause', 'resume', 'restart', 'abort', 'message']);

test('the control section is for admins and the organizer; a player cannot open it', function () {
    $tournament = ctlKnockout();

    Livewire::actingAs(ctlPlayer(ctlMatch($tournament, 1), 0))->test('tournament-control', ['tournament' => $tournament])->assertForbidden();
    Livewire::actingAs(organizer())->test('tournament-control', ['tournament' => $tournament])->assertForbidden();
    Livewire::actingAs($tournament->creator)->test('tournament-control', ['tournament' => $tournament])->assertOk()->assertSee('Tournament control');
});

test('the control section sets a result with a typed reason and survives a roundtrip', function () {
    $tournament = ctlKnockout(2);
    $admin = ctlAdmin();
    $match = ctlMatch($tournament, 1);

    Livewire::actingAs($admin)->test('tournament-control', ['tournament' => $tournament])
        ->call('edit', $match->id)
        ->set('seriesWinner', '1')
        ->set('loserGames', 1)
        ->call('setResult')
        ->assertSet('error', __('Give a reason of 3 to 500 characters. The players read it.'))
        ->set('resultReason', 'Confirmed by the referee')
        ->call('setResult')
        ->assertSet('error', '')
        ->assertSet('editing', null)
        ->assertDispatched('tournament-controlled')
        ->call('$refresh')->assertOk();

    expect(ctlMatch($tournament, 1)->result)->toMatchArray(['winner' => 1, 'label' => '1 : 3', 'by' => 'control']);
});
