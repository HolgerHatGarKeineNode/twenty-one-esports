<?php

namespace App\Support\Notifications;

use App\Enums\BoardEndReason;
use App\Enums\NotificationKind;
use App\Games\GameRegistry;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * The notifications of correspondence board games (plan "Mühle und Dame",
 * P8), in the recipient's language, over the same kinds and switches as
 * daily chess (ChessNotifications), so a player's settings hold for both:
 *
 * - your_move: the opponent made their correspondence move
 * - reminder: a correspondence move is due soon (`board:daily-reminders`)
 * - challenge: someone challenged you to a correspondence game
 * - game_started: your correspondence challenge was accepted
 * - opponent_resigned / game_over: a correspondence game ended
 *
 * The links do not need the board game routes: routes/board.php is loaded
 * only while the board games are switched on at boot, and the clock sweep
 * still ends (and reports) the games of a board game switched off since.
 *
 * Live board games notify nothing: their players are at the board. The
 * notice carries no match number: a board game's id is no league number
 * and would share a push tag with a chess game of the same id.
 */
final class BoardNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function yourMove(BoardGame $game): void
    {
        $game->refresh();
        $player = $game->player($game->turn);

        if (! $game->isActive() || $player === null) {
            return;
        }

        $locale = $this->locale($player);
        $last = $game->moves()->reorder('ply', 'desc')->first();

        $this->notifier->send($player, NotificationKind::YourMove, new Notice(
            __('Your move in :game correspondence', ['game' => $this->name($game, $locale)], $locale),
            __(':name played :move. You have until :deadline.', [
                'name' => $game->opponentOf($player)?->displayName() ?? '',
                'move' => $last === null ? '' : $last->ply.'. '.$last->notation,
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            self::gameUrl($game),
            null,
            __('Play your move', [], $locale),
        ), $game);
    }

    public function reminder(BoardGame $game): void
    {
        $player = $game->player($game->turn);

        if ($player === null) {
            return;
        }

        $locale = $this->locale($player);
        $hours = max(1, (int) ceil(max(0, (int) $game->deadline_ms - now()->getTimestampMs()) / 3_600_000));

        $this->notifier->send($player, NotificationKind::Reminder, new Notice(
            __(':game correspondence: :hours h left', ['game' => $this->name($game, $locale), 'hours' => $hours], $locale),
            __('Your move against :name is due :deadline. No move by then and you lose on time.', [
                'name' => $game->opponentOf($player)?->displayName() ?? '',
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            self::gameUrl($game),
        ), $game);
    }

    /**
     * `remote: false` once the player's daily cap of challenge notifications
     * off the page is reached (BoardChallenges): bell and page only.
     */
    public function challengeReceived(BoardChallenge $challenge, bool $remote = true): void
    {
        $player = $challenge->challenged;
        $locale = $this->locale($player);
        $color = match ($challenge->color) {
            'white' => __('Black', [], $locale),
            'black' => __('White', [], $locale),
            default => __('a random colour', [], $locale),
        };
        $kind = $challenge->rated ? __('Rated', [], $locale) : __('Casual', [], $locale);

        // One line, no links, at most 140 characters (PlainText); the DM cleans the rest.
        $message = PlainText::line((string) $challenge->message, 140);

        $this->notifier->send($player, NotificationKind::Challenge, new Notice(
            __(':name challenges you to :game correspondence', ['name' => $challenge->challenger->displayName(), 'game' => $this->gameName($challenge->game, $locale)], $locale),
            $message !== ''
                ? __('":message" · :kind, you play :color.', ['message' => $message, 'kind' => $kind, 'color' => $color], $locale)
                : __(':kind, you play :color. Open for :hours hours.', ['kind' => $kind, 'color' => $color, 'hours' => (int) config('esports.board_games.correspondence.challenge_hours')], $locale),
            self::correspondenceUrl($challenge->game),
            null,
            __('Answer', [], $locale),
        ), remote: $remote, sender: $challenge->challenger);
    }

    /**
     * The correspondence challenge was accepted: the challenger's game is on.
     */
    public function gameStarted(BoardGame $game, User $challenger): void
    {
        $locale = $this->locale($challenger);
        $white = $game->colorOf($challenger) === 'w';

        $this->notifier->send($challenger, NotificationKind::GameStarted, new Notice(
            __(':name accepted your :game challenge', ['name' => $game->opponentOf($challenger)?->displayName() ?? '', 'game' => $this->name($game, $locale)], $locale),
            $white
                ? __(':game correspondence · you play White, your move.', ['game' => $this->name($game, $locale)], $locale)
                : __(':game correspondence · you play Black, their move.', ['game' => $this->name($game, $locale)], $locale),
            self::gameUrl($game),
            null,
            __('Open game', [], $locale),
        ), $game);
    }

    /**
     * Both players of an ended correspondence game, on every channel.
     */
    public function gameOver(BoardGame $game): void
    {
        $game->refresh();

        foreach ([$game->white, $game->black] as $player) {
            if ($player === null) {
                continue;
            }

            $locale = $this->locale($player);
            $color = $game->colorOf($player);
            $won = $game->result !== null && $game->result !== '1/2-1/2' && ($game->result === '1-0') === ($color === 'w');
            $outcome = match (true) {
                $game->result === null => 'aborted',
                $game->result === '1/2-1/2' => 'draw',
                $won => 'win',
                default => 'loss',
            };
            $url = self::gameUrl($game);
            $name = $this->name($game, $locale);

            if ($won && $game->end_reason === BoardEndReason::Resignation->value) {
                $this->notifier->send($player, NotificationKind::OpponentResigned, new Notice(
                    __(':name resigned', ['name' => $game->opponentOf($player)?->displayName() ?? ''], $locale),
                    __(':game correspondence · you won', ['game' => $name], $locale),
                    $url,
                    null,
                    __('See the game', [], $locale),
                ), $game);

                continue;
            }

            $label = match ($outcome) {
                'aborted' => __('aborted, nothing counts', [], $locale),
                'draw' => __('draw', [], $locale),
                'win' => __('you won', [], $locale),
                default => __('you lost', [], $locale),
            };

            $this->notifier->send($player, NotificationKind::GameOver, new Notice(
                __(':game correspondence is over', ['game' => $name], $locale),
                __(':outcome · :reason', ['outcome' => $label, 'reason' => $this->reason($game, $locale)], $locale),
                $url,
                null,
                __('See the game', [], $locale),
                $outcome === 'aborted' ? 'ping' : $outcome,
            ), $game);
        }
    }

    /** The board page of this game, as routes/board.php names it (`board/{boardGame}`), registered or not. */
    public static function gameUrl(BoardGame $game): string
    {
        return Route::has('board.show') ? route('board.show', $game) : url('board/'.$game->id);
    }

    /** The correspondence page of a board game (`games/{board}/correspondence`), registered or not. */
    public static function correspondenceUrl(string $slug): string
    {
        return Route::has('board.correspondence') ? route('board.correspondence', $slug) : url('games/'.$slug.'/correspondence');
    }

    private function name(BoardGame $game, string $locale): string
    {
        return $this->gameName($game->game, $locale);
    }

    private function gameName(string $slug, string $locale): string
    {
        return __(app(GameRegistry::class)->name($slug), [], $locale);
    }

    /**
     * The end reason as the board page names it: the core's own, or the
     * rules' (a mill count, a threefold repetition).
     */
    private function reason(BoardGame $game, string $locale): string
    {
        $labels = [
            BoardEndReason::Resignation->value => 'Resignation', BoardEndReason::Timeout->value => 'Out of time',
            BoardEndReason::Agreement->value => 'Draw by agreement', BoardEndReason::Aborted->value => 'Aborted',
            BoardEndReason::Forfeit->value => 'No first move', BoardEndReason::Voided->value => 'Voided by the league',
            ...app(BoardGameService::class)->rulesOf($game)?->reasons() ?? [],
        ];
        $reason = (string) $game->end_reason;

        return __($labels[$reason] ?? $reason, [], $locale);
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }

    private function deadline(BoardGame $game, User $user): string
    {
        $deadline = Carbon::createFromTimestampMs((int) $game->deadline_ms)->setTimezone($user->timezone ?? (string) config('app.timezone'));
        $deadline->locale($this->locale($user));

        return $deadline->isoFormat('ddd YYYY-MM-DD HH:mm');
    }
}
