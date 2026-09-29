<?php

namespace App\Support\Board;

use App\Enums\BoardGameStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Support\Chess\ChessRuleViolation;

/**
 * One live game at a time across chess and the board games (plan "Mühle und
 * Dame", P5), from the chess side, without a line of chess code changed:
 * chess asks itself and the casual 1v1 before a live game starts
 * (ChessGameService::start(), ChessQueue::join()), but not the board games,
 * which it must not know. So the board games answer for themselves, on the
 * two chess rows that start or wait for a live game:
 *
 * - a live chess game (not daily) is not created for a player in a live
 *   board game: `already_playing`, the reason chess itself refuses a second
 *   live game with, so every caller already handles it (the lobby shows the
 *   message, the tournament matchmaker tries again on its next run);
 * - a player in a live board game does not join the blitz queue.
 *
 * A correspondence board game (P8) is no live game and blocks neither.
 *
 * A board game that starts takes its players out of the chess queue
 * (BoardGameService::start()), so a waiting chess player is never paired
 * with someone who plays a board game by now. While the board games are
 * switched off nothing is asked.
 */
final class LiveGameGuard
{
    public static function register(): void
    {
        ChessGame::creating(function (ChessGame $game): void {
            if ($game->mode !== ChessGame::CORRESPONDENCE) {
                self::refuseBusy([$game->white_id, $game->black_id]);
            }
        });

        ChessQueueEntry::creating(fn (ChessQueueEntry $entry) => self::refuseBusy([$entry->user_id]));
    }

    /**
     * @param  list<int|null>  $userIds
     *
     * @throws ChessRuleViolation when one of them plays a live board game
     */
    private static function refuseBusy(array $userIds): void
    {
        $userIds = array_values(array_filter($userIds, fn (?int $id): bool => $id !== null));

        if (! config('esports.board_games.enabled') || $userIds === []) {
            return;
        }

        $busy = BoardGame::query()->live()->where('status', BoardGameStatus::Active)
            ->where(fn ($query) => $query->whereIn('white_id', $userIds)->orWhereIn('black_id', $userIds))
            ->exists();

        if ($busy) {
            throw new ChessRuleViolation('already_playing', __('Finish your board game first.'));
        }
    }
}
