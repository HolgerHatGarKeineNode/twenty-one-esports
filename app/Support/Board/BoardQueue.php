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
use App\Support\Rating\Ratings;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
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
 * asks again (the searching lobby polls), so a widening range pairs without
 * anyone new joining. An open invite for this board game comes first: a
 * player who searches while holding one is paired with its inviter.
 *
 * One intent at a time: searching here ends a search for chess, for another
 * board game and for a casual 1v1, and withdraws the casual invite sent; a
 * player in any live game cannot search. Casual only: board games have no
 * rated queue before they mine (P6).
 */
final class BoardQueue
{
    public function __construct(
        private BoardGameService $games,
        private BoardInvites $invites,
        private GameRegistry $registry,
        private CasualInvites $casualInvites,
    ) {}

    /**
     * Join (or stay in) the queue of this board game and try to pair at once.
     *
     * @throws BoardRuleViolation for a board game that is not switched on, or a player already in a live game
     */
    public function join(User $user, string $slug, string $mode = 'blitz'): ?BoardGame
    {
        $definition = $this->registry->find($slug);

        if (! $definition instanceof BoardGameDefinition || $definition->mode($mode) === null) {
            throw new BoardRuleViolation('unknown_game');
        }

        self::assertFree($this->games, $user);

        // One intent at a time: searching here ends every other search and the casual invite sent.
        ChessQueueEntry::query()->where('user_id', $user->id)->delete();
        SeriesQueueEntry::query()->where('user_id', $user->id)->delete();
        BoardQueueEntry::query()->where('user_id', $user->id)->where(fn ($query) => $query->where('game', '!=', $slug)->orWhere('mode', '!=', $mode))->delete();
        $this->casualInvites->withdrawOutgoing($user);
        $this->invites->withdrawOutgoing($user);

        $invited = $this->fromOpenInvite($user, $slug, $mode);

        if ($invited !== null) {
            return $invited;
        }

        BoardQueueEntry::query()->firstOrCreate(['user_id' => $user->id], [
            'game' => $slug,
            'mode' => $mode,
            'rating' => Ratings::forUsers([$user->id], $slug, $mode, Rating::CASUAL)[$user->id]['rating'],
            'joined_at' => now(),
        ]);

        return $this->pair($user);
    }

    /**
     * One live game at a time across games: a live board game, a live chess
     * game or a running casual 1v1 keeps a player from searching or inviting.
     *
     * @throws BoardRuleViolation
     */
    public static function assertFree(BoardGameService $games, User $user): void
    {
        if ($games->activeGameOf($user) !== null) {
            throw new BoardRuleViolation('already_playing');
        }

        if (app(ChessGameService::class)->activeGameOf($user) !== null || CasualMatches::runningMatchOf($user) !== null) {
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

            $now = now();
            $candidates = BoardQueueEntry::query()
                ->where('user_id', '!=', $user->id)
                ->where('game', $entry->game)
                ->where('mode', $entry->mode)
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->with('user')
                ->get();

            foreach ($candidates as $candidate) {
                $distance = abs($entry->rating - $candidate->rating);

                if ($distance > min($this->range($entry, $now), $this->range($candidate, $now))) {
                    continue;
                }

                [$white, $black] = random_int(0, 1) === 0 ? [$user, $candidate->user] : [$candidate->user, $user];

                try {
                    return $this->games->start($entry->game, $white, $black, $entry->mode);
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
