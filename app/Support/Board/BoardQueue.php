<?php

namespace App\Support\Board;

use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\Rating;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Pong\PongMatches;
use App\Support\Rating\Ratings;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
use App\Support\Tournaments\CupMatchNow;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The casual queue of each board game other than chess (plan "Mühle und
 * Dame", P5), built after App\Support\Chess\ChessQueue and not on it:
 * players who search a game of one board game right now. Two entries of the
 * same board game and mode pair when their casual ratings of that game are
 * within BOTH players' current range; the range widens the longer a player
 * waits (`esports.board_games.queue.range`, as chess).
 *
 * Pairing is tried when a player joins and whenever a waiting player's page
 * asks again (the searching lobby asks when its range widens, nextWidening),
 * so a widening range pairs without anyone new joining. An open invite for this board game comes first: a
 * player who searches while holding one is paired with its inviter.
 *
 * One intent at a time: searching here ends a search for chess, for another
 * board game and for a casual 1v1, and withdraws the casual invite sent; a
 * player in any live game cannot search.
 *
 * Rated (P6, RatedBoard): a rated search is refused while rated play of the
 * board game is closed for the player, pairs only with another rated search
 * of the same game and mode, by the rated rating of that game, and only when
 * the trust gate passes for the two (both Trusted, listing each other); the
 * gate is pinned with the game. Rated and casual searches never pair.
 */
final class BoardQueue
{
    public function __construct(
        private BoardGameService $games,
        private BoardInvites $invites,
        private GameRegistry $registry,
        private CasualInvites $casualInvites,
        private RatedBoard $ratedBoard,
    ) {}

    /**
     * Join (or stay in) the queue of this board game and try to pair at once.
     *
     * @throws BoardRuleViolation for a board game that is not switched on, or a player already in a live game
     */
    public function join(User $user, string $slug, string $mode = 'blitz', bool $rated = false): ?BoardGame
    {
        $definition = $this->registry->find($slug);

        if (! $definition instanceof BoardGameDefinition || $definition->mode($mode) === null) {
            throw new BoardRuleViolation('unknown_game');
        }

        self::assertFree($this->games, $user);

        // The casual lock (user, 2026-10-03): an open cup match in a running round comes first.
        if (($cup = CupMatchNow::refusal($user)) !== null) {
            throw new BoardRuleViolation($cup['reason'], $cup['message']);
        }

        // Rated (P6): only while the season is live, the rated queue is offered and the player is Trusted.
        $refusal = $rated ? $this->ratedBoard->refusal($user, $slug, $mode) : null;

        if ($refusal !== null) {
            throw new BoardRuleViolation('rated_not_open', $refusal);
        }

        // One intent at a time: searching here ends every other search and the casual invite sent.
        ChessQueueEntry::query()->where('user_id', $user->id)->delete();
        SeriesQueueEntry::query()->where('user_id', $user->id)->delete();
        BoardQueueEntry::query()->where('user_id', $user->id)->where(fn ($query) => $query->where('game', '!=', $slug)->orWhere('mode', '!=', $mode))->delete();
        $this->casualInvites->withdrawOutgoing($user);
        $this->invites->withdrawOutgoing($user);

        // An open invite is casual: it pairs a casual search only.
        $invited = $rated ? null : $this->fromOpenInvite($user, $slug, $mode);

        if ($invited !== null) {
            return $invited;
        }

        BoardQueueEntry::query()->firstOrCreate(['user_id' => $user->id], [
            'game' => $slug,
            'mode' => $mode,
            'rated' => $rated,
            'rating' => Ratings::forUsers([$user->id], $slug, $mode, Ratings::pool($rated))[$user->id]['rating'],
            'joined_at' => now(),
        ]);

        return $this->pair($user);
    }

    /**
     * One live game at a time across games: a live board game, a live chess
     * game, a running casual 1v1 or a Proof of Pong match (plan "Proof of
     * Pong", P4) keeps a player from searching or inviting.
     *
     * @throws BoardRuleViolation
     */
    public static function assertFree(BoardGameService $games, User $user): void
    {
        if ($games->activeGameOf($user) !== null) {
            throw new BoardRuleViolation('already_playing');
        }

        if (app(ChessGameService::class)->activeGameOf($user) !== null || CasualMatches::runningMatchOf($user) !== null || PongMatches::runningMatchOf($user) !== null) {
            throw new BoardRuleViolation('playing_elsewhere');
        }
    }

    /**
     * The newest open invite for this board game and mode, accepted as a
     * found match. An inviter who plays by now makes the accept fail, and
     * the next one is tried.
     */
    private function fromOpenInvite(User $user, string $slug, string $mode): ?BoardGame
    {
        foreach ($this->invites->incoming($user)->where('game', $slug)->where('mode', $mode)->whereNull('tournament_match_id') as $invite) {
            try {
                return $this->invites->accept($invite, $user);
            } catch (BoardRuleViolation) {
                continue;
            }
        }

        return null;
    }

    public function leave(User $user): void
    {
        BoardQueueEntry::query()->where('user_id', $user->id)->delete();
    }

    public function entryOf(User $user): ?BoardQueueEntry
    {
        return BoardQueueEntry::query()->where('user_id', $user->id)->first();
    }

    /**
     * Rating distance this entry accepts right now: `initial`, plus `step`
     * for every full `every_seconds` waited, never above `max`.
     */
    public function range(BoardQueueEntry $entry, ?CarbonInterface $now = null): int
    {
        $config = config('esports.board_games.queue.range');
        $waited = max(0, (int) $entry->joined_at->diffInSeconds($now ?? now()));
        $steps = intdiv($waited, max(1, (int) $config['every_seconds']));

        return min((int) $config['max'], (int) $config['initial'] + $steps * (int) $config['step']);
    }

    /**
     * When this entry's range next opens, or null once it is at `max`. The
     * searching lobby asks for a pairing then: nobody new has to join for a
     * wider range to fit, so no push would announce it.
     */
    public function nextWidening(BoardQueueEntry $entry, ?CarbonInterface $now = null): ?CarbonInterface
    {
        $now ??= now();
        $config = config('esports.board_games.queue.range');

        if ($this->range($entry, $now) >= (int) $config['max']) {
            return null;
        }

        $every = max(1, (int) $config['every_seconds']);
        $waited = max(0, (int) $entry->joined_at->diffInSeconds($now));

        return $entry->joined_at->copy()->addSeconds((intdiv($waited, $every) + 1) * $every);
    }

    /**
     * Pair this player with the longest-waiting fitting opponent of the same
     * board game and mode, if any. Returns the new game, or the live board
     * game a pairing already gave them.
     */
    public function pair(User $user): ?BoardGame
    {
        return DB::transaction(function () use ($user): ?BoardGame {
            $entry = BoardQueueEntry::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($entry === null) {
                return $this->games->activeGameOf($user);
            }

            // A cup match opened while this player searched (the casual lock), or his team match locked: the search ends.
            if (CupMatchNow::lockReason($user) !== null) {
                $entry->delete();

                return null;
            }

            $now = now();
            $candidates = BoardQueueEntry::query()
                ->where('user_id', '!=', $user->id)
                ->where('game', $entry->game)
                ->where('mode', $entry->mode)
                ->where('rated', $entry->rated)
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->with('user')
                ->get();

            foreach ($candidates as $candidate) {
                if (CupMatchNow::lockReason($candidate->user) !== null) {
                    $candidate->delete();

                    continue;
                }

                $distance = abs($entry->rating - $candidate->rating);

                if ($distance > min($this->range($entry, $now), $this->range($candidate, $now))) {
                    continue;
                }

                [$white, $black] = random_int(0, 1) === 0 ? [$user, $candidate->user] : [$candidate->user, $user];

                // Rated (P6): the pairing is the accept; its trust gate is pinned with the game.
                $gate = $entry->rated ? $this->ratedBoard->pin($white, $black) : null;

                if ($entry->rated && $gate === null) {
                    continue;
                }

                try {
                    return $this->games->start($entry->game, $white, $black, $entry->mode, ratedGate: $gate);
                } catch (BoardRuleViolation) {
                    // That player plays elsewhere by now: they stop searching here.
                    $candidate->delete();

                    continue;
                }
            }

            return null;
        });
    }
}
