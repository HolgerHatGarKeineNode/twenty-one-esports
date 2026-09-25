<?php

namespace App\Support\Notifications;

use App\Enums\ChessEndReason;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The four chess notifications (P5b), each written in the recipient's
 * language and handed to the Notifier:
 *
 * - your_move: the opponent made their daily move
 * - reminder: a daily move is due soon (ChessSettings "Remind me when")
 * - challenge: someone challenged you to daily chess
 * - game_over: a daily game ended
 */
final class ChessNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function yourMove(ChessGame $game): void
    {
        $game->refresh();

        if (! $game->isActive()) {
            return;
        }

        $player = $game->player($game->turn());
        $opponent = $game->opponentOf($player);
        $last = $game->moves()->reorder('ply', 'desc')->first();
        $locale = $this->locale($player);

        $this->notifier->send($player, 'your_move', new Notice(
            __('Your move in daily chess :number', ['number' => $game->number()], $locale),
            __(':name played :move. You have until :deadline.', [
                'name' => $opponent?->displayName() ?? '',
                'move' => $last === null ? '' : $this->moveLabel($last->ply, $last->san),
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            route('games.show', $game),
            $game->id,
        ), $game);
    }

    public function reminder(ChessGame $game): void
    {
        $player = $game->player($game->turn());
        $opponent = $game->opponentOf($player);
        $locale = $this->locale($player);
        $hours = max(1, (int) ceil(max(0, (int) $game->deadline_ms - now()->getTimestampMs()) / 3_600_000));

        $this->notifier->send($player, 'reminder', new Notice(
            __('Daily chess :number: :hours h left', ['number' => $game->number(), 'hours' => $hours], $locale),
            __('Your move against :name is due :deadline. No move by then and you lose on time.', [
                'name' => $opponent?->displayName() ?? '',
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            route('games.show', $game),
            $game->id,
        ), $game);
    }

    public function challengeReceived(ChessChallenge $challenge): void
    {
        $player = $challenge->challenged;
        $locale = $this->locale($player);
        $color = match ($challenge->color) {
            'white' => __('Black', [], $locale),
            'black' => __('White', [], $locale),
            default => __('a random colour', [], $locale),
        };

        $this->notifier->send($player, 'challenge', new Notice(
            __(':name challenges you to daily chess', ['name' => $challenge->challenger->displayName()], $locale),
            filled($challenge->message)
                ? __('":message" · Casual, you play :color.', ['message' => $challenge->message, 'color' => $color], $locale)
                : __('Casual, you play :color. Open for :hours hours.', ['color' => $color, 'hours' => (int) config('esports.chess.challenge_hours')], $locale),
            route('me.correspondence'),
        ));
    }

    public function gameOver(ChessGame $game): void
    {
        $game->refresh();

        foreach ([$game->white, $game->black] as $player) {
            $locale = $this->locale($player);
            $color = $game->colorOf($player);
            $outcome = match (true) {
                $game->result === null => __('aborted, nothing counts', [], $locale),
                $game->result === '1/2-1/2' => __('draw', [], $locale),
                ($game->result === '1-0') === ($color === 'w') => __('you won', [], $locale),
                default => __('you lost', [], $locale),
            };

            $this->notifier->send($player, 'game_over', new Notice(
                __('Daily chess :number is over', ['number' => $game->number()], $locale),
                __(':outcome · :reason', [
                    'outcome' => $outcome,
                    'reason' => __(($game->end_reason ?? ChessEndReason::Aborted)->label(), [], $locale),
                ], $locale),
                route('games.show', $game),
                $game->id,
            ), $game);
        }
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }

    private function moveLabel(int $ply, string $san): string
    {
        return intdiv($ply + 1, 2).($ply % 2 === 1 ? '. ' : '… ').$san;
    }

    private function deadline(ChessGame $game, User $user): string
    {
        $deadline = Carbon::createFromTimestampMs((int) $game->deadline_ms)->setTimezone($user->timezone ?? (string) config('app.timezone'));
        $deadline->locale($this->locale($user));

        return $deadline->isoFormat('ddd YYYY-MM-DD HH:mm');
    }
}
