<?php

namespace App\Support\Chess;

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Rating\EloRating;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chess team matches between two clans' `chess/rapid` lineups (plan "Schach
 * Rapid und Clan", P4; NIP rev. 9.22, "Chess team matches"). The challenge
 * and the answer are a series' (App\Support\Series\SeriesService with
 * `boards`); everything after the accept is here:
 *
 * - **Lineup.** Until `esports.team_matches.lock_minutes` before the start
 *   each captain names exactly `boards` players from {@see choices()}: the
 *   active lineup players, in a rated match only those the accept pinned as
 *   Trusted. The other side does not see the names before the lock
 *   ({@see visibleTo()}).
 * - **Lock** ({@see lock()}, `teammatches:tick`). Each side is ordered by
 *   rapid Elo ({@see standing()}): the rated rapid rating of the season, else
 *   the casual one, else the start rating; ties go to more results on that
 *   ladder, then to the older account at the league. Board k is player k
 *   against player k. A side that named nobody loses the whole team match by
 *   forfeit; neither side: void (user, 2026-10-05: "es bringt nichts, wenn
 *   wir es automatisch setzen und die Leute nicht da sind"). Both go through
 *   the league's own decision path (SeriesService::leagueClose()), which
 *   attests a rated match once, unrated.
 * - **Reservation** ({@see reservationOf()}). From the lock until the team
 *   match ends its players start no other game: no queue, no invite, no game
 *   start (the casual lock of App\Support\Tournaments\CupMatchNow, which
 *   asks {@see refusal()}).
 * - **Pair limit** ({@see ratedPairBlocked()}): one rated team match per
 *   pair of clans in a rolling `rated_pair_days`; friendlies are unlimited.
 *
 * Starting the boards, board forfeits and the team result come with P5.
 */
final class ChessTeamMatches
{
    /** The reason code of a refused action: the player's own team match comes first. */
    public const RESERVED = 'team_match_first';

    /** The reason code when the other player is reserved for a team match. */
    public const OTHER_RESERVED = 'opponent_in_team_match';

    public function __construct(private SeriesService $series) {}

    public static function lockMinutes(): int
    {
        return max(1, (int) config('esports.team_matches.lock_minutes', 30));
    }

    /** When the lineups lock: `lock_minutes` before the agreed start; null before an accept. */
    public static function lockAt(SeriesMatch $match): ?CarbonInterface
    {
        return $match->start_at?->copy()->subMinutes(self::lockMinutes());
    }

    /* ---------- Pair limit ----------------------------------------------------------------------------------------- */

    /**
     * Whether the two lineups' clans already have a rated team match in the
     * rolling window: one that is still open or that was not declined,
     * withdrawn or expired, starting (or, not yet accepted, created) within
     * the last `rated_pair_days`. The pair is unordered: a clan has one
     * `chess/rapid` lineup (database review 2026-10-05).
     */
    public static function ratedPairBlocked(int $lineupA, int $lineupB): bool
    {
        $since = now()->subDays(max(1, (int) config('esports.team_matches.rated_pair_days', 7)));

        return SeriesMatch::query()
            ->whereNotNull('boards')->where('rated', true)
            ->whereNotIn('status', [SeriesStatus::Declined, SeriesStatus::Withdrawn, SeriesStatus::Expired])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('challenger_lineup_id', $lineupA)->where('challenged_lineup_id', $lineupB))
                ->orWhere(fn (Builder $q) => $q->where('challenger_lineup_id', $lineupB)->where('challenged_lineup_id', $lineupA)))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->whereNotNull('start_at')->where('start_at', '>', $since))
                ->orWhere(fn (Builder $q) => $q->whereNull('start_at')->where('created_at', '>', $since)))
            ->exists();
    }

    public static function pairLimitRefusal(): SeriesRuleViolation
    {
        return new SeriesRuleViolation('rated_pair_limit', __('These two clans played a rated team match in the last :days days. Make this one a friendly, or challenge them again later.', [
            'days' => max(1, (int) config('esports.team_matches.rated_pair_days', 7)),
        ]));
    }

    /* ---------- Lineup -------------------------------------------------------------------------------------------- */

    /**
     * The players a captain may name for a side: the active lineup players;
     * in a rated match the ones the accept pinned as Trusted (NIP rev. 9.22,
     * "only players who pass the trust gate on rank").
     *
     * @return list<LineupSeat>
     */
    public function choices(SeriesMatch $match, string $side): array
    {
        return $this->series->rosterChoices($match, $side);
    }

    /** The captains name their players now: accepted, not locked, the lock still ahead. */
    public static function lineupOpen(SeriesMatch $match): bool
    {
        $lockAt = self::lockAt($match);

        return $match->isTeamMatch() && $match->status === SeriesStatus::Accepted && $match->lineup_locked_at === null
            && $lockAt !== null && $lockAt->isFuture();
    }

    /**
     * A captain names exactly `boards` players for his side. Replaces his
     * earlier choice; the order he names them in does not count (the lock
     * orders by Elo).
     *
     * @param  list<int>  $userIds
     *
     * @throws SeriesRuleViolation
     */
    public function name(SeriesMatch $match, User $captain, array $userIds): void
    {
        $ids = array_values(array_unique(array_map(intval(...), $userIds)));

        DB::transaction(function () use ($match, $captain, $ids): void {
            $locked = SeriesMatch::query()->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember'])
                ->lockForUpdate()->find($match->id);

            if ($locked === null || ! $locked->isTeamMatch()) {
                throw new SeriesRuleViolation('not_team_match', __('This is not a team match.'));
            }

            $side = $locked->captainSideOf($captain);

            if ($side === null) {
                throw new SeriesRuleViolation('not_captain', __('Only a captain can name the players.'));
            }

            if (! self::lineupOpen($locked)) {
                throw new SeriesRuleViolation('lineup_closed', __('The lineup can be named from the accept until :minutes minutes before the start.', ['minutes' => self::lockMinutes()]));
            }

            if (count($ids) !== $locked->boards) {
                throw new SeriesRuleViolation('lineup_count', __('Pick exactly :count players, one per board.', ['count' => $locked->boards]));
            }

            $allowed = array_map(fn (LineupSeat $seat): int => $seat->user_id, $this->choices($locked, $side));

            if (array_diff($ids, $allowed) !== []) {
                throw new SeriesRuleViolation('lineup_not_eligible', $locked->rated
                    ? __('Only players who were in the lineup and Trusted when the match was accepted can play this rated team match.')
                    : __('Only active players of your lineup can play.'));
            }

            SeriesMatchBoard::query()->where('series_match_id', $locked->id)->where('side', $side)->delete();

            foreach ($ids as $userId) {
                SeriesMatchBoard::query()->create(['series_match_id' => $locked->id, 'side' => $side, 'user_id' => $userId]);
            }
        });

        $this->series->broadcastChange($match);
    }

    /**
     * Whether `$viewer` may see a side's players: everyone once the lineups
     * are locked; before, only the side's own players and captains (NIP rev.
     * 9.22: "The other side does not see the choice before the lock").
     */
    public static function visibleTo(SeriesMatch $match, string $side, ?User $viewer): bool
    {
        if ($match->lineup_locked_at !== null) {
            return true;
        }

        return $viewer !== null && $match->participantSideOf($viewer) === $side;
    }

    /**
     * A side's named players as `$viewer` may see them: board order once
     * locked, else the captain's pick; null while hidden from the viewer.
     *
     * @return Collection<int, SeriesMatchBoard>|null
     */
    public function lineupFor(SeriesMatch $match, string $side, ?User $viewer): ?Collection
    {
        if (! self::visibleTo($match, $side, $viewer)) {
            return null;
        }

        return SeriesMatchBoard::query()->with('user')->where('series_match_id', $match->id)->where('side', $side)
            ->orderBy('board')->orderBy('id')->get();
    }

    /** Whether a side has named its players (without saying whom: the other side may ask this). */
    public static function hasNamed(SeriesMatch $match, string $side): bool
    {
        return SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->count() === $match->boards;
    }

    /* ---------- Lock ---------------------------------------------------------------------------------------------- */

    /**
     * Every accepted team match whose lock is due, locked once each.
     *
     * @return array{locked: int, forfeited: int, voided: int}
     */
    public function lockDue(): array
    {
        $done = ['locked' => 0, 'forfeited' => 0, 'voided' => 0];
        $due = SeriesMatch::query()->whereNotNull('boards')->where('status', SeriesStatus::Accepted)->whereNull('lineup_locked_at')
            ->whereNotNull('start_at')->where('start_at', '<=', now()->addMinutes(self::lockMinutes()))
            ->orderBy('start_at')->get();

        // Each match on its own: one that throws is reported and the others still lock on time.
        foreach ($due as $match) {
            try {
                $outcome = $this->lock($match);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            match ($outcome) {
                'locked' => $done['locked']++,
                'forfeit' => $done['forfeited']++,
                'void' => $done['voided']++,
                default => null,
            };
        }

        return $done;
    }

    /**
     * Lock one team match: order both sides and freeze the boards, or
     * forfeit (one side named nobody) or void it (neither did). Once only:
     * the match row is locked and read again, a second run finds it locked
     * or decided. Returns what happened, null when nothing was due.
     *
     * @return 'locked'|'forfeit'|'void'|null
     */
    public function lock(SeriesMatch $match): ?string
    {
        $outcome = DB::transaction(function () use ($match): ?array {
            $locked = SeriesMatch::query()->lockForUpdate()->find($match->id);
            $lockAt = $locked === null ? null : self::lockAt($locked);

            if ($locked === null || ! $locked->isTeamMatch() || $locked->status !== SeriesStatus::Accepted || $locked->lineup_locked_at !== null
                || $lockAt === null || $lockAt->isFuture()) {
                return null;
            }

            $picks = SeriesMatchBoard::query()->with('user')->where('series_match_id', $locked->id)->get()->groupBy('side');
            $named = array_values(array_filter(SeriesMatch::SIDES, fn (string $side): bool => count($picks->get($side) ?? []) === $locked->boards));

            if (count($named) !== 2) {
                return ['outcome' => $named === [] ? 'void' : 'forfeit', 'winner' => $named[0] ?? 'none'];
            }

            foreach (SeriesMatch::SIDES as $side) {
                $this->freeze($picks->get($side) ?? collect());
            }

            $locked->update(['lineup_locked_at' => now()]);

            return ['outcome' => 'locked'];
        });

        if ($outcome === null) {
            return null;
        }

        if ($outcome['outcome'] === 'locked') {
            $this->reserve($match);
            $this->series->broadcastChange($match);

            return 'locked';
        }

        $forfeit = $outcome['outcome'] === 'forfeit';
        $reason = $forfeit
            ? 'No lineup named by the lineup lock, '.self::lockMinutes().' minutes before the start; the other side wins the team match by forfeit.'
            : 'Neither side named a lineup by the lineup lock, '.self::lockMinutes().' minutes before the start.';

        // The league's own decision (attested once and unrated when rated), only while the lock is still due and open.
        $decided = $this->series->leagueClose($match, [
            'resolution' => $forfeit ? SeriesResolution::Forfeit : SeriesResolution::Void,
            'winner' => $outcome['winner'],
            'games' => null,
        ], $reason, null, fn (SeriesMatch $now): bool => $now->status === SeriesStatus::Accepted && $now->lineup_locked_at === null && (self::lockAt($now)?->isPast() ?? false));

        if (! $decided) {
            return null;
        }

        SeriesMatch::query()->whereKey($match->id)->whereNull('lineup_locked_at')->update(['lineup_locked_at' => now()]);

        return $forfeit ? 'forfeit' : 'void';
    }

    /**
     * One side's board order, written with the rating it was ordered by.
     *
     * @param  Collection<int, SeriesMatchBoard>  $picks
     */
    private function freeze(Collection $picks): void
    {
        $rows = $picks->map(function (SeriesMatchBoard $pick): array {
            $standing = $pick->user === null ? ['rating' => null, 'pool' => null, 'results' => null] : $this->standing($pick->user);

            return ['pick' => $pick, ...$standing];
        })->all();

        usort($rows, fn (array $a, array $b): int => self::compare($a, $b));

        foreach ($rows as $index => $row) {
            $row['pick']->update(['board' => $index + 1, 'rating' => $row['rating'], 'rating_pool' => $row['pool'], 'rating_results' => $row['results']]);
        }
    }

    /**
     * Board order: the higher rapid rating first; then more results on that
     * ladder; then the older account at the league; a deleted account last.
     *
     * @param  array{pick: SeriesMatchBoard, rating: int|null, results: int|null}  $a
     * @param  array{pick: SeriesMatchBoard, rating: int|null, results: int|null}  $b
     */
    public static function compare(array $a, array $b): int
    {
        $userA = $a['pick']->user;
        $userB = $b['pick']->user;

        if ($userA === null || $userB === null) {
            return ($userA === null) <=> ($userB === null);
        }

        return [$b['rating'], $b['results'], $userA->created_at?->getTimestamp() ?? PHP_INT_MAX, $userA->id]
            <=> [$a['rating'], $a['results'], $userB->created_at?->getTimestamp() ?? PHP_INT_MAX, $userB->id];
    }

    /**
     * A player's rapid Elo for the board order (NIP rev. 9.22, "Board
     * order"): the rating on the season's rated rapid ladder, else the
     * casual rapid rating, else the start rating; with the results on that
     * ladder.
     *
     * @return array{rating: int, pool: 'rated'|'casual'|'start', results: int}
     */
    public function standing(User $user): array
    {
        $season = Ladders::isOpen('chess', 'rapid') ? Ladders::season() : null;
        $base = Rating::query()->where(['game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$user->id]);
        $rated = $season === null ? null : (clone $base)->where('pool', Rating::RATED)->where('season', $season)->first();
        $rating = $rated ?? (clone $base)->where('pool', Rating::CASUAL)->where('season', '')->first();

        return $rating === null
            ? ['rating' => EloRating::fromConfig('rating')->start, 'pool' => 'start', 'results' => 0]
            : ['rating' => $rating->rating, 'pool' => $rating->pool, 'results' => $rating->results];
    }

    /* ---------- Reservation --------------------------------------------------------------------------------------- */

    /**
     * From the lock on, the named players stop searching anywhere: the queues
     * would pair them into a game they could not start.
     */
    private function reserve(SeriesMatch $match): void
    {
        $ids = SeriesMatchBoard::query()->where('series_match_id', $match->id)->whereNotNull('user_id')->pluck('user_id')->all();

        ChessQueueEntry::query()->whereIn('user_id', $ids)->delete();
        BoardQueueEntry::query()->whereIn('user_id', $ids)->delete();
        SeriesQueueEntry::query()->whereIn('user_id', $ids)->delete();
    }

    /**
     * The locked team match a player is reserved for, while it runs; null
     * when free. From the lock until the team match has its result.
     */
    public static function reservationOf(User $user): ?SeriesMatch
    {
        return SeriesMatch::query()->whereNotNull('boards')->where('status', SeriesStatus::Accepted)->whereNotNull('lineup_locked_at')
            ->whereHas('boardPlayers', fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('start_at')->first();
    }

    /**
     * The refusal of another game for a reserved player (`team_match_first`)
     * or because the other player is reserved (`opponent_in_team_match`);
     * null when both are free.
     *
     * @return array{reason: string, message: string}|null
     */
    public static function refusal(User $actor, ?User $other = null): ?array
    {
        if (self::reservationOf($actor) !== null) {
            return ['reason' => self::RESERVED, 'message' => (string) __('Your clan match comes first.')];
        }

        if ($other !== null && self::reservationOf($other) !== null) {
            return ['reason' => self::OTHER_RESERVED, 'message' => (string) __(':name plays a clan match right now.', ['name' => $other->displayName()])];
        }

        return null;
    }
}
