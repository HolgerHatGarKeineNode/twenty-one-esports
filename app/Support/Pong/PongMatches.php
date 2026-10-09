<?php

namespace App\Support\Pong;

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Events\PongMatchUpdated;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live Proof of Pong matches (plan "Proof of Pong", P2): the only writer of App\Models\PongMatch. A match is created
 * for two players (an accepted invite, a rematch), waits until both have opened its page, then plays rally after
 * rally under the referee (PongReferee) until one side wins, a player resigns or stays away too long.
 *
 * Every request of a player's page (sync(), report()) is also the referee's clock: it says that player is there,
 * starts the match once both are, pauses it while a player has not been heard from for `away_seconds`, resumes it when
 * they are back, ends it after `forfeit_seconds` (the absent player loses) and decides the contacts nobody reported in
 * time. `pong:check-clocks` does the same every ten seconds for matches nobody asks about (sweep()). A match not started
 * within `start_seconds` is aborted.
 *
 * Each change is pushed to both pages as a snapshot (PongMatchUpdated); a finished rated match moves both players'
 * Elo in the same transaction (PongRatings).
 *
 * A tournament's match (P4, TournamentMatchMaker) waits for its players as long as the tournament's check-in
 * (`startMs` in its state): whoever is there by then wins it by forfeit, nobody there calls it off. Once it is over
 * (after the commit) its result goes back to the bracket (TournamentRunner::pongMatchFinished(), ::pongMatchAborted()).
 */
final class PongMatches
{
    public function __construct(private PongRatings $ratings) {}

    /**
     * A new match: `$left` plays side 0, `$right` side 1. Whoever opens it is the caller's business (an accepted
     * invite tells the inviter, PongInvites::accept(); a rematch moves both pages; a tournament tells both players).
     * `$tournamentMatchId` and `$startSeconds`: the tournament match it plays and how long it waits for its players.
     */
    public function create(User $left, User $right, ?PongMatch $rematchOf = null, ?int $tournamentMatchId = null, ?int $startSeconds = null): PongMatch
    {
        $match = PongMatch::query()->create([
            'left_id' => $left->id,
            'right_id' => $right->id,
            'seed' => random_int(0, 0xFFFFFFFF),
            'status' => PongMatchStatus::Waiting,
            'state' => [
                'ref' => null,
                'speed' => max(1, (int) config('esports.pong.live_speed', 1)),
                'seen' => [null, null],
                'rematch' => [false, false],
                'next' => null,
                'version' => 1,
                // The players' figures (P3, PongCast), by side; a rematch keeps each player's, sides swapped.
                'figures' => $rematchOf === null ? [null, null] : array_reverse(self::figuresOf($rematchOf)),
                // A tournament's match waits for its players as long as its check-in (P4); null: `start_seconds`.
                'startMs' => $startSeconds === null ? null : max(1, $startSeconds) * 1000,
            ],
            'log' => [],
            'rated' => PongRatings::offered(),
            'rematch_of_id' => $rematchOf?->id,
            'tournament_match_id' => $tournamentMatchId,
        ]);

        return $match;
    }

    /**
     * The match this player waits for or plays, if any (one live game at a time).
     */
    public static function activeMatchOf(User $user): ?PongMatch
    {
        return PongMatch::query()->running()->playedBy($user)->latest('id')->first();
    }

    /**
     * The match this player waits for or plays while the game is switched on, else null: what the other games ask
     * before a live game of theirs starts (BoardGameService::start(), BoardQueue::assertFree(), LiveGameGuard).
     */
    public static function runningMatchOf(User $user): ?PongMatch
    {
        return config('esports.pong.enabled') ? self::activeMatchOf($user) : null;
    }

    /**
     * A player's page is here: their heartbeat, and the clock.
     *
     * @return array<string, mixed> the snapshot
     */
    public function sync(PongMatch $match, User $user): array
    {
        return $this->locked($match, function (PongMatch $match, int $now) use ($user): bool {
            $side = $match->sideOf($user);

            if ($side !== null && ! $match->isOver()) {
                $state = $match->state;
                $state['seen'][$side] = $now;
                $match->state = $state;
            }

            return $this->tick($match, $now);
        }, $user);
    }

    /**
     * The defender's report on a contact (PongReferee::report()).
     *
     * @param  array{rally: int, ball: int, tick: int, kind: string, y?: int|null}  $report
     * @return array{result: string, snapshot: array<string, mixed>}
     */
    public function report(PongMatch $match, User $user, array $report): array
    {
        $result = 'stale';

        $snapshot = $this->locked($match, function (PongMatch $match, int $now) use ($user, $report, &$result): bool {
            $side = $match->sideOf($user);
            $state = $match->state;

            if ($side === null || $match->status !== PongMatchStatus::Active || $state['ref'] === null) {
                return false;
            }

            $state['seen'][$side] = $now;
            $match->state = $state;
            $referee = $this->referee($match);

            if ($referee->rally !== $report['rally']) {
                $result = $report['rally'] < $referee->rally ? 'duplicate' : 'desync';

                return $this->tick($match, $now);
            }

            $result = $referee->report($side, $report['ball'], $report['tick'], $report['kind'], $report['y'] ?? null);
            $changed = in_array($result, ['hit', 'miss'], true);

            if ($changed) {
                $referee->nextIfOver($now);
                $this->store($match, $referee);
            }

            return $this->tick($match, $now) || $changed;
        }, $user);

        return ['result' => $result, 'snapshot' => $snapshot];
    }

    /**
     * A player gives up: the other wins.
     *
     * @return array<string, mixed>
     */
    public function resign(PongMatch $match, User $user): array
    {
        return $this->locked($match, function (PongMatch $match) use ($user): bool {
            $side = $match->sideOf($user);

            if ($side === null || $match->isOver()) {
                return false;
            }

            // Never started (nobody served yet): called off, no winner, no Elo, no list entry (review 2026-10-10:
            // resigning an unopened match paid the other side a win without a game).
            if ($match->started_at === null) {
                $this->end($match, null, PongEndReason::Abort);

                return true;
            }

            $this->finish($match, 1 - $side, PongEndReason::Resign);

            return true;
        }, $user);
    }

    /**
     * A player offers a rematch after the end; once both did, the new match starts with the sides swapped.
     *
     * @return array<string, mixed>
     */
    public function rematch(PongMatch $match, User $user): array
    {
        return $this->locked($match, function (PongMatch $match) use ($user): bool {
            $side = $match->sideOf($user);
            $state = $match->state;

            if ($side === null || $match->status !== PongMatchStatus::Finished || $state['next'] !== null || $state['rematch'][$side]) {
                return false;
            }

            $state['rematch'][$side] = true;

            if ($state['rematch'] === [true, true]) {
                $left = $match->right;
                $right = $match->left;
                $busy = $left === null || $right === null
                    || PongInvites::busy($left) !== null || PongInvites::busy($right) !== null;

                if (! $busy) {
                    $state['next'] = $this->create($left, $right, $match)->ulid;
                } else {
                    // One of them is in another match now: both offers lapse, either may offer again later
                    // (review 2026-10-10: the flags stayed set and every further click was refused).
                    $state['rematch'] = [false, false];
                }
            }

            $match->state = $state;

            return true;
        }, $user);
    }

    /**
     * A player picks their figure (P3, the cast of App\Support\Pong\PongCast) for this match: before it starts or
     * while nobody has scored yet. Both pages show both picks. An id that is not one of the cast's players changes
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function figure(PongMatch $match, User $user, string $figure): array
    {
        return $this->locked($match, function (PongMatch $match) use ($user, $figure): bool {
            $side = $match->sideOf($user);
            $open = $match->status === PongMatchStatus::Waiting
                || ($match->status === PongMatchStatus::Active && $match->score() === [0, 0]);

            if ($side === null || ! $open || ! PongCast::isPlayer($figure) || self::figuresOf($match)[$side] === $figure) {
                return false;
            }

            $state = $match->state;
            $state['figures'] = self::figuresOf($match);
            $state['figures'][$side] = $figure;
            $match->state = $state;

            return true;
        }, $user);
    }

    /**
     * The match's figures by side (null: not picked); a match from before P3 has none.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function figuresOf(PongMatch $match): array
    {
        $figures = $match->state['figures'] ?? [null, null];

        return [$figures[0] ?? null, $figures[1] ?? null];
    }

    /**
     * Every match that waits or plays, on the server's clock: for those nobody asks about (pong:check-clocks).
     *
     * @return int how many changed
     */
    public function sweep(): int
    {
        $changed = 0;

        foreach (PongMatch::query()->running()->get() as $match) {
            // Each match on its own: one that throws is reported and the others still move on.
            try {
                $this->locked($match, function (PongMatch $match, int $now) use (&$changed): bool {
                    $moved = $this->tick($match, $now);
                    $changed += $moved ? 1 : 0;

                    return $moved;
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $changed;
    }

    /**
     * What a page needs to show the match and re-sync its rally: the referee's state, the score, who is there, the
     * end and the rematch. `me` is the viewer's side, null for anybody else; `now` the server's clock.
     *
     * @return array<string, mixed>
     */
    public function snapshot(PongMatch $match, ?User $viewer = null): array
    {
        $state = $match->state;
        $now = self::now();
        $away = $this->awaySide($match, $now);

        return [
            'id' => $match->ulid,
            'status' => $match->status->value,
            'me' => $match->sideOf($viewer),
            'version' => (int) $state['version'],
            'now' => $now,
            'speed' => (int) $state['speed'],
            'seed' => $match->seed,
            'score' => $match->score(),
            'ref' => $state['ref'],
            'away' => $away,
            'awaySince' => $away === null ? null : $state['seen'][$away],
            'forfeitMs' => self::forfeitMs(),
            'startBy' => $match->created_at === null ? null : $match->created_at->getTimestampMs() + self::startMsOf($match),
            'winner' => $match->winner_id === null ? null : ($match->winner_id === $match->left_id ? 0 : 1),
            'endReason' => $match->end_reason?->value,
            'rated' => $match->rated,
            'ratings' => $match->left_rating_after === null ? null : [
                [$match->left_rating_before, $match->left_rating_after],
                [$match->right_rating_before, $match->right_rating_after],
            ],
            'rematch' => $state['rematch'],
            'figures' => self::figuresOf($match),
            'next' => $state['next'] === null ? null : route('pong.match', ['match' => $state['next']]),
        ];
    }

    /**
     * Runs `$change` on the row locked; a change (true) is saved with a new version and pushed to both pages.
     *
     * @param  callable(PongMatch, int): bool  $change
     * @return array<string, mixed>
     */
    private function locked(PongMatch $match, callable $change, ?User $viewer = null): array
    {
        $ended = false;
        $fresh = DB::transaction(function () use ($match, $change, &$ended): PongMatch {
            $fresh = PongMatch::query()->lockForUpdate()->findOrFail($match->id);
            $now = self::now();
            $seenBefore = $fresh->state['seen'];
            $wasOver = $fresh->isOver();

            if ($change($fresh, $now)) {
                $state = $fresh->state;
                $state['version'] = (int) $state['version'] + 1;
                $fresh->state = $state;
                $fresh->save();
                Broadcasts::send(new PongMatchUpdated($fresh->ulid, $this->snapshot($fresh)));
            } elseif ($fresh->state['seen'] !== $seenBefore) {
                // A heartbeat alone: saved, pushed to nobody.
                $fresh->save();
            }

            $ended = ! $wasOver && $fresh->isOver();

            return $fresh;
        });

        if ($ended && $fresh->tournament_match_id !== null) {
            $this->reportToTournament($fresh);
        }

        return $this->snapshot($fresh, $viewer);
    }

    /**
     * A tournament's match is over (P4): its winner, or that it was called off, goes back to the bracket. A failure
     * is reported, never the player's request; `pong:check-clocks` reports it again
     * (TournamentRunner::reportUnreportedPongMatches()).
     */
    private function reportToTournament(PongMatch $match): void
    {
        try {
            $runner = app(TournamentRunner::class);
            $match->status === PongMatchStatus::Finished ? $runner->pongMatchFinished($match->id) : $runner->pongMatchAborted($match->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The clock of one match: start, pause, resume, forfeit, abort, and the contacts nobody reported in time.
     *
     * @return bool whether anything changed
     */
    private function tick(PongMatch $match, int $now): bool
    {
        $state = $match->state;

        if ($match->status === PongMatchStatus::Waiting) {
            if ($state['seen'][0] !== null && $state['seen'][1] !== null) {
                $referee = PongReferee::start($match->seed, $this->rules(), $now, (int) $state['speed']);
                $match->forceFill(['status' => PongMatchStatus::Active, 'started_at' => now()]);
                $this->store($match, $referee);

                return true;
            }

            if ($match->created_at !== null && $now > $match->created_at->getTimestampMs() + self::startMsOf($match)) {
                $present = array_keys(array_filter($state['seen'], fn (?int $seen): bool => $seen !== null));

                // A tournament's match (P4): the player who is there wins it, the one who never came loses by forfeit.
                if ($match->tournament_match_id !== null && count($present) === 1) {
                    // Never played: no Elo moves.
                    $match->rated = false;
                    $this->finish($match, $present[0], PongEndReason::Forfeit);

                    return true;
                }

                $this->end($match, null, PongEndReason::Abort);

                return true;
            }

            return false;
        }

        if ($match->status !== PongMatchStatus::Active) {
            return false;
        }

        $referee = $this->referee($match);
        $away = $this->awaySide($match, $now);
        $before = $referee->version;

        if ($away !== null) {
            $referee->pause($now);
            $gone = array_filter([0, 1], fn (int $side): bool => $now - (int) $state['seen'][$side] > self::forfeitMs());

            if (count($gone) === 2) {
                $this->store($match, $referee);
                $this->end($match, null, PongEndReason::Abort);

                return true;
            }

            if (count($gone) === 1) {
                $this->store($match, $referee);
                $this->finish($match, 1 - array_values($gone)[0], PongEndReason::Forfeit);

                return true;
            }
        } else {
            $referee->resume($now);
            $referee->due($now);
            $referee->nextIfOver($now);
        }

        if ($referee->version === $before) {
            return false;
        }

        $this->store($match, $referee);

        return true;
    }

    /**
     * The side not heard from for `away_seconds`, or null (both there, or the match not in play).
     */
    private function awaySide(PongMatch $match, int $now): ?int
    {
        if ($match->status !== PongMatchStatus::Active) {
            return null;
        }

        $seen = $match->state['seen'];
        $away = array_filter([0, 1], fn (int $side): bool => $now - (int) $seen[$side] > self::awayMs());

        // Both gone: the one gone longer counts.
        return $away === [] ? null : (count($away) === 2 ? ((int) $seen[0] <= (int) $seen[1] ? 0 : 1) : array_values($away)[0]);
    }

    /**
     * The referee's state, the score and its log written back onto the match; a won game finishes it.
     */
    private function store(PongMatch $match, PongReferee $referee): void
    {
        $state = $match->state;
        $state['ref'] = $referee->toArray();
        $match->state = $state;
        $match->score_left = $referee->score[0];
        $match->score_right = $referee->score[1];
        $match->log = [...$match->log, ...$referee->takeLog()];

        if ($referee->winner !== null && $match->status === PongMatchStatus::Active) {
            $this->finish($match, $referee->winner, PongEndReason::Score);
        }
    }

    private function finish(PongMatch $match, int $winner, PongEndReason $reason): void
    {
        $this->end($match, $match->player($winner), $reason);
        $match->save();
        $this->ratings->apply($match);
    }

    private function end(PongMatch $match, ?User $winner, PongEndReason $reason): void
    {
        $match->forceFill([
            'status' => $winner === null ? PongMatchStatus::Aborted : PongMatchStatus::Finished,
            'winner_id' => $winner?->id,
            'end_reason' => $reason,
            'ended_at' => now(),
        ]);
        $match->log = [...$match->log, ['end', $reason->value, $winner === null ? null : $match->sideOf($winner)]];
    }

    private function referee(PongMatch $match): PongReferee
    {
        return PongReferee::fromArray($match->seed, $this->rules(), $match->state['ref'], (int) $match->state['speed']);
    }

    private function rules(): PongRules
    {
        return PongRules::fromConfig((array) config('esports.pong'));
    }

    /** The server's clock in Unix ms (time travel in tests moves it). */
    public static function now(): int
    {
        return (int) now()->getTimestampMs();
    }

    private static function awayMs(): int
    {
        return (int) round((float) config('esports.pong.away_seconds', 5) * 1000);
    }

    private static function forfeitMs(): int
    {
        return (int) round((float) config('esports.pong.forfeit_seconds', 30) * 1000);
    }

    private static function startMs(): int
    {
        return (int) round((float) config('esports.pong.start_seconds', 60) * 1000);
    }

    /** How long this match waits for its players: a tournament's its check-in, every other `start_seconds`. */
    private static function startMsOf(PongMatch $match): int
    {
        $own = $match->state['startMs'] ?? null;

        return is_int($own) && $own > 0 ? $own : self::startMs();
    }
}
