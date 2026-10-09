<?php

namespace App\Support\Board;

use App\Enums\BoardGameStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\PongMatch;
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
 * Proof of Pong (plan "Proof of Pong", P2) answers on the same two rows: a
 * player in a running live match (waiting for both players or in play) gets
 * no live chess game and does not join the blitz queue, while its switch is
 * on. Its own invites ask the other way round (PongInvites::busy()).
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
     * @throws ChessRuleViolation when one of them plays a live board game or a Proof of Pong match
     */
    private static function refuseBusy(array $userIds): void
    {
        $userIds = array_values(array_filter($userIds, fn (?int $id): bool => $id !== null));

        if ($userIds === []) {
            return;
        }

        if (config('esports.pong.enabled') && PongMatch::query()->running()
            ->where(fn ($query) => $query->whereIn('left_id', $userIds)->orWhereIn('right_id', $userIds))->exists()) {
            throw new ChessRuleViolation('already_playing', __('Finish your Proof of Pong match first.'));
        }

        if (! config('esports.board_games.enabled')) {
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
