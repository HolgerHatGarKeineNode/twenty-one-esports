<?php

namespace App\Support\Scores;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\Contracts\ScoreSource;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Tournaments\TournamentInterest;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Running a score game's leaderboard tournament (plan "AoE2 und Trackmania",
 * P4): its course, the snapshots of the automatic sources, a director's
 * entry or correction, and the end.
 *
 * The bracket is the engine's Leaderboard (one `board` match holding every
 * entry). Finalizing writes the leaderboard into that match as a ranked
 * result (`by` = `scores`, `ranks` per slot, `unplaced` = the entries without
 * a valid value) and moves the bracket: the tournament finishes, and places
 * and payouts follow the existing path (App\Support\Payouts\TournamentPlacements,
 * which leaves `unplaced` entries out, so nobody without a value wins a share).
 *
 * The league finalizes on its own once the window closed and
 * `esports.score_games.review_hours` passed with no manual submission left
 * for an admin; a director or admin without a stake in it can finalize
 * earlier, but never before submissions closed (window end plus the manual
 * grace) nor while one still waits for an admin (security gate F3).
 */
final class ScoreLeaderboards
{
    public function __construct(private ScoreRuns $runs, private TournamentRunner $runner, private GameRegistry $games) {}

    /**
     * Whether a director or admin may run this leaderboard (course, entries, the end).
     */
    public static function mayDirect(Tournament $tournament, ?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $tournament->isDirectedBy($user));
    }

    /**
     * Whether the user has a stake in the leaderboard and so may neither
     * correct a value, nor review a submission, nor end it (security gate F1):
     * the tournament gate P8b (App\Support\Tournaments\TournamentInterest) on
     * the leaderboard's one board, which holds every entry, so playing in it,
     * a clan in it, or being named by someone who does counts. Admins are
     * checked without the chain of appointments, as everywhere.
     */
    public static function interested(Tournament $tournament, User $user): bool
    {
        $board = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'board')->first();

        if ($board === null) {
            return $tournament->participants()->get()->contains(fn ($participant): bool => in_array($user->id, $participant->memberIds(), true));
        }

        return TournamentInterest::of($tournament, $board, $user, followAppointers: ! $user->isAdmin());
    }

    public static function interestMessage(): string
    {
        return __('You have an interest in this leaderboard (you play in it, belong to a clan in it, or were named by someone who does), so another director or an admin has to do this.');
    }

    /**
     * Set the course, until the window opens.
     *
     * @throws TournamentRuleViolation
     */
    public function setCourse(Tournament $tournament, User $actor, string $course): void
    {
        $this->assertDirector($tournament, $actor);
        $game = $this->runs->gameOf($tournament);
        $course = trim($course);

        if (! $game->isCourse($course)) {
            throw new TournamentRuleViolation('course', __('That is no :course id of this game.', ['course' => __($game->courseLabel())]));
        }

        if (ScoreWindow::of($tournament)->hasStarted() && $tournament->score_course !== null) {
            throw new TournamentRuleViolation('course_locked', __('The window is open, so the course stays as it is.'));
        }

        $tournament->forceFill(['score_course' => $course])->save();
    }

    /**
     * Enter or correct an entry's value, with the reason (the audit trail:
     * a new director run, the earlier ones stay). A null value takes the
     * entry off the leaderboard. Nobody enters their own value.
     *
     * @throws TournamentRuleViolation
     */
    public function correct(Tournament $tournament, User $director, int $userId, ?int $value, string $reason): ScoreRun
    {
        $this->assertDirector($tournament, $director);
        $reason = trim($reason);

        if ($tournament->status !== TournamentStatus::Running) {
            throw new TournamentRuleViolation('not_running', __('Only a running leaderboard can be corrected.'));
        }

        if ($director->id === $userId || self::interested($tournament, $director)) {
            throw new TournamentRuleViolation('interested', self::interestMessage());
        }

        if (! $tournament->participants()->where('user_id', $userId)->exists()) {
            throw new TournamentRuleViolation('not_entered', __('That player is not in this leaderboard.'));
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 500 characters: it is kept with the value.'));
        }

        $course = $this->runs->courseOf($tournament) ?? throw new TournamentRuleViolation('no_course', __('Set the course first.'));

        if ($value !== null && $value < 0) {
            throw new TournamentRuleViolation('value', __('A value is never negative.'));
        }

        $window = ScoreWindow::of($tournament);
        $at = now()->lessThan($window->end) ? now() : $window->end->subSecond();

        return ScoreRun::query()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $userId,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'course' => $course->id,
            'value' => $value,
            'unit' => $course->metric()->unit,
            'source' => ScoreRun::DIRECTOR,
            // A director's entry counts in the window it is entered for.
            'achieved_at' => $at->lessThan($window->start) ? $window->start : $at,
            'verified_at' => now(),
            'verified_by_id' => $director->id,
            'note' => $reason,
        ]);
    }

    /**
     * Read every automatic source of the game for every entry and store what
     * lies inside the window. A source that is down is reported and skipped
     * for the rest of the snapshot; one that refuses a single player (a 4xx)
     * counts as failed for that player, and the next is asked. What a source
     * gave before stays.
     *
     * @return array{stored: int, failed: int}
     */
    public function snapshot(Tournament $tournament): array
    {
        $course = $this->runs->courseOf($tournament);
        $window = ScoreWindow::of($tournament);

        if ($course === null || $tournament->status !== TournamentStatus::Running || ! $window->hasStarted()) {
            return ['stored' => 0, 'failed' => 0];
        }

        $stored = 0;
        $failed = 0;
        $users = User::query()->whereIn('id', $tournament->participants()->pluck('user_id')->filter()->all())->get();

        foreach ($course->game->sources() as $class) {
            $source = app($class);

            if (! $source instanceof ScoreSource) {
                continue;
            }

            foreach ($users as $user) {
                $account = ScoreAccount::of($user, $course->game);

                try {
                    $record = $source->bestFor($account, $course, $window->start, $window->end);
                } catch (ScoreSourceUnavailable $e) {
                    // A source that is down is not asked again for every other entry in this snapshot (re-audit: one
                    // stalling source held a snapshot of four entries for 12 minutes). Its earlier reads stay.
                    report($e);
                    $failed++;

                    // Round-3 S3: a refusal of one player (one account the API does not know) skips only that player.
                    if ($e->sourceDown) {
                        continue 2;
                    }

                    continue;
                }

                if ($record !== null && $this->runs->store($record, $user->id, $course, $window, $account->accountId) !== null) {
                    $stored++;
                }
            }
        }

        return ['stored' => $stored, 'failed' => $failed];
    }

    /**
     * Write the leaderboard into the bracket and finish the tournament.
     * `$actor` null: the league on its own (due()).
     *
     * @throws TournamentRuleViolation
     */
    public function finalize(Tournament $tournament, ?User $actor = null): void
    {
        if ($actor !== null) {
            $this->assertDirector($tournament, $actor);

            if (self::interested($tournament, $actor)) {
                throw new TournamentRuleViolation('interested', self::interestMessage());
            }
        }

        if ($tournament->status !== TournamentStatus::Running) {
            throw new TournamentRuleViolation('not_running', __('This leaderboard is not running.'));
        }

        if (! ScoreWindow::of($tournament)->hasEnded()) {
            throw new TournamentRuleViolation('window_open', __('The window is still open.'));
        }

        // Security gate F3: a value may still come in, and every waiting one is decided first.
        if (now()->lessThan(ManualSubmissions::closesAt($tournament))) {
            throw new TournamentRuleViolation('grace', __('Submissions are still taken until :at. End the leaderboard after that.', ['at' => LeagueTime::stamp(ManualSubmissions::closesAt($tournament))]));
        }

        if (ScoreRun::query()->pendingReview()->where('tournament_id', $tournament->id)->exists()) {
            throw new TournamentRuleViolation('pending', __('Submissions still wait for an admin. End the leaderboard once every one is decided.'));
        }

        // Re-audit F4: a finish of an id a player stored waits for an admin to confirm whose it is. Round-3 S1: only
        // until the review time is over; then the end leaves it out (logged), so it never depends on anybody.
        $game = $this->runs->gameOf($tournament);
        $leaveOut = self::reviewOver($tournament);

        if (! $leaveOut && ScoreAccounts::waitsFor($tournament, $game)) {
            throw new TournamentRuleViolation('accounts', __('Finishes of an account a player stored wait for an admin to confirm whose it is. End the leaderboard once they are decided.'));
        }

        DB::transaction(function () use ($tournament, $actor, $game, $leaveOut): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $match = TournamentMatch::query()->where('tournament_id', $locked->id)->where('bracket', 'board')->with('slots')->first();

            if ($match === null || $match->result !== null) {
                return;
            }

            if ($leaveOut) {
                ScoreAccounts::leaveOut($locked, $game);
            }

            $standings = $this->runs->standings($locked);
            $placeOf = [];
            $unplaced = [];

            foreach ($standings as $standing) {
                if ($standing->place === null) {
                    $unplaced[] = $standing->participant->id;
                } else {
                    $placeOf[$standing->participant->id] = $standing->place;
                }
            }

            $next = count($placeOf);
            $ranks = [];

            foreach ($match->slots as $slot) {
                $id = $slot->tournament_participant_id;
                $ranks[$slot->slot] = $id !== null && isset($placeOf[$id]) ? $placeOf[$id] : ++$next;
            }

            ksort($ranks);
            $ranks = array_values($ranks);
            $winner = $ranks === [] ? null : array_search(min($ranks), $ranks, true);

            $this->runner->store($match, [
                'winner' => is_int($winner) ? $winner : null,
                'ranks' => $ranks,
                'unplaced' => $unplaced,
                // Round-4 F1: the final standings as they were at the end; the page never reads them live again.
                'standings' => array_map(fn (ScoreStanding $standing): array => ['participant' => $standing->participant->id, 'place' => $standing->place, 'value' => $standing->value,
                    'at' => $standing->achievedAt?->toIso8601String(), 'source' => $standing->source, 'run' => $standing->runId], $standings),
                'label' => __('Leaderboard'),
                'by' => 'scores',
                'finalized_by' => $actor === null ? null : ['user_id' => $actor->id, 'name' => $actor->displayName()],
                'at' => now()->toIso8601String(),
            ]);
        });

        $this->runner->sync($tournament->refresh(), 'result');
    }

    /**
     * An admin's review of the top places of a window whose game asks for
     * one (ScoreGame::reviewedPlaces(), plan "Blockfill", P7): the runs
     * behind them as they stand now are marked as looked at by this admin
     * (`verified_by_id`; a director's entry carries its director already).
     * Once the window has closed, while the board runs or after its end; a
     * correction that moves another run into the top places needs a review
     * of its own. Returns how many runs were newly marked.
     *
     * @throws TournamentRuleViolation
     */
    public function confirmReview(Tournament $tournament, User $admin): int
    {
        $this->assertDirector($tournament, $admin);

        if (self::interested($tournament, $admin)) {
            throw new TournamentRuleViolation('interested', self::interestMessage());
        }

        if ($this->runs->gameOf($tournament)->reviewedPlaces() === 0) {
            throw new TournamentRuleViolation('no_review', __('This leaderboard needs no review of its top places.'));
        }

        if (! in_array($tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true) || ! ScoreWindow::of($tournament)->hasEnded()) {
            throw new TournamentRuleViolation('not_ended', __('The top places are reviewed once the window has closed.'));
        }

        $runIds = array_values(array_filter(array_map(fn (ScoreStanding $standing): ?int => $standing->runId, $this->reviewedStandings($tournament))));

        if ($runIds === []) {
            throw new TournamentRuleViolation('nobody_placed', __('Nobody has a place to review yet.'));
        }

        return ScoreRun::query()->whereKey($runIds)->whereNull('verified_by_id')->update(['verified_by_id' => $admin->id]);
    }

    /**
     * Whether an admin reviewed every run behind the top places a game asks
     * to be reviewed (confirmReview()); true for a game that asks for none,
     * false while nobody is placed. Fails closed: a place without its run,
     * or a run not looked at, is not reviewed.
     */
    public function reviewed(Tournament $tournament): bool
    {
        if ($this->runs->gameOf($tournament)->reviewedPlaces() === 0) {
            return true;
        }

        $top = $this->reviewedStandings($tournament);
        $runIds = array_map(fn (ScoreStanding $standing): ?int => $standing->runId, $top);

        if ($top === [] || in_array(null, $runIds, true)) {
            return false;
        }

        return ScoreRun::query()->whereKey($runIds)->whereNotNull('verified_by_id')->count() === count(array_unique($runIds));
    }

    /**
     * The placed standings a review covers: places 1 to the game's reviewedPlaces().
     *
     * @return list<ScoreStanding>
     */
    private function reviewedStandings(Tournament $tournament): array
    {
        $places = $this->runs->gameOf($tournament)->reviewedPlaces();

        return array_values(array_filter($this->runs->standings($tournament), fn (ScoreStanding $standing): bool => $standing->place !== null && $standing->place <= $places));
    }

    /**
     * Whether the review time after the window (`review_hours`) is over.
     */
    public static function reviewOver(Tournament $tournament): bool
    {
        return ! now()->lessThan(ScoreWindow::of($tournament)->end->addHours(max(0, (int) config('esports.score_games.review_hours', 24))));
    }

    /**
     * Take the snapshots of every running leaderboard, then finalize those
     * whose review time is over and that have no manual submission waiting,
     * and attest the league's own finished windows that are due for the
     * season chain (their count is not part of the return).
     *
     * @return array{finalized: int, snapshots: int}
     */
    public function tick(): array
    {
        $finalized = 0;
        $snapshots = 0;

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->whereIn('game', array_keys($this->games->scores()))->get() as $tournament) {
            try {
                $window = ScoreWindow::of($tournament);
                // Once more after the window closed: a best set in its last hour is read before the end is written.
                $snapshots += $this->snapshot($tournament)['stored'];

                // After the review time an id still waiting for an admin is left out by finalize() (round-3 S1).
                if (! $window->hasEnded() || ! self::reviewOver($tournament) || now()->lessThan(ManualSubmissions::closesAt($tournament)) || ScoreRun::query()->pendingReview()->where('tournament_id', $tournament->id)->exists()) {
                    continue;
                }

                $this->finalize($tournament);
                $finalized++;
            } catch (Throwable $e) {
                // Each leaderboard on its own: one that fails is reported, the others go on.
                report($e);
            }
        }

        // Plan "AoE2 und Trackmania", P7: the windows the league opened itself go to the season chain once final and
        // reviewed (SeasonChains::attestDueScoreWindows(), each on its own; nothing without a live season).
        app(SeasonChains::class)->attestDueScoreWindows();

        return ['finalized' => $finalized, 'snapshots' => $snapshots];
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function assertDirector(Tournament $tournament, User $user): void
    {
        if (! self::mayDirect($tournament, $user)) {
            throw new TournamentRuleViolation('not_director', __('Only a director of this tournament or an admin can do that.'));
        }
    }
}
