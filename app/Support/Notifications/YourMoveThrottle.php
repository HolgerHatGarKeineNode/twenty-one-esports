<?php

namespace App\Support\Notifications;

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Whether a "your move" of a correspondence game (daily chess, Mühle and
 * Dame) may leave the site as a browser push (never a DM, see
 * NotificationKind::dmAllowed()). The bell and the open pages get every one
 * regardless (Notifier); this only decides the remote copy.
 *
 * Two players moving back and forth within minutes got one DM per move
 * (bug report 2026-09-30). Two rules, `esports.notifications.your_move`:
 *
 * - `at_board_minutes`: none while the player made their own previous move
 *   in this game less than this long ago. They are at the board.
 * - `per_game_minutes`: at most one per player and game in this span. The
 *   first one claims it (atomic Cache::add), later ones stay in the app.
 *   A lost cache entry costs one extra notice, never a missing one.
 *
 * Moves alternate in every two-sided game we host (ChessRules, BoardRules),
 * so the player's own previous move is the one before the move just played.
 * A Hyperbitcoinization match (plan "Hyperbitcoinization", P3) has many
 * actions per turn and bots that answer at once: there the player's own
 * latest action in the match counts.
 */
final class YourMoveThrottle
{
    public function allowsRemote(User $user, ChessGame|BoardGame|HyperMatch $game): bool
    {
        $atBoard = max(0, (int) config('esports.notifications.your_move.at_board_minutes'));
        $perGame = max(0, (int) config('esports.notifications.your_move.per_game_minutes'));

        if ($atBoard > 0) {
            $ownMove = $game instanceof HyperMatch
                ? $game->actions()->reorder('ply', 'desc')->where('source', HyperAction::PLAYER)->where('seat', $game->seatOf($user)?->seat)->first()
                : $game->moves()->where('ply', $game->ply - 1)->first();

            if ($ownMove?->created_at !== null && $ownMove->created_at->gt(now()->subMinutes($atBoard))) {
                return false;
            }
        }

        if ($perGame === 0) {
            return true;
        }

        $key = 'your-move-remote:'.match (true) {
            $game instanceof ChessGame => 'chess',
            $game instanceof HyperMatch => 'hyper',
            default => 'board',
        }.':'.$game->id.':'.$user->id;

        return Cache::add($key, true, now()->addMinutes($perGame));
    }
}
