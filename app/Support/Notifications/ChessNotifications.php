<?php

namespace App\Support\Notifications;

use App\Enums\ChessEndReason;
use App\Enums\NotificationKind;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\User;
use App\Support\Chess\SanNotation;
use Illuminate\Support\Carbon;

/**
 * The chess notifications, each written in the recipient's language and
 * handed to the Notifier (NotificationKind lists them all):
 *
 * - your_move: the opponent made their daily move
 * - reminder: a daily move is due soon (ChessSettings "Remind me when")
 * - challenge: someone challenged you to daily chess
 * - game_started: your daily challenge was accepted (P5c)
 * - match_found, invite, invite_accepted: live blitz, in the app only (P5c)
 * - opponent_resigned / game_over: a game ended; the resigning player gets
 *   game_over, the other one opponent_resigned (P5c: blitz too, in the app)
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

        $this->notifier->send($player, NotificationKind::YourMove, new Notice(
            __('Your move in daily chess :number', ['number' => $game->number()], $locale),
            __(':name played :move. You have until :deadline.', [
                'name' => $opponent?->displayName() ?? '',
                'move' => $last === null ? '' : $this->moveLabel($last->ply, $last->san, $locale),
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            route('games.show', $game),
            $game->id,
            __('Play your move', [], $locale),
        ), $game);
    }

    public function reminder(ChessGame $game): void
    {
        $player = $game->player($game->turn());
        $opponent = $game->opponentOf($player);
        $locale = $this->locale($player);
        $hours = max(1, (int) ceil(max(0, (int) $game->deadline_ms - now()->getTimestampMs()) / 3_600_000));

        $this->notifier->send($player, NotificationKind::Reminder, new Notice(
            __('Daily chess :number: :hours h left', ['number' => $game->number(), 'hours' => $hours], $locale),
            __('Your move against :name is due :deadline. No move by then and you lose on time.', [
                'name' => $opponent?->displayName() ?? '',
                'deadline' => $this->deadline($game, $player),
            ], $locale),
            route('games.show', $game),
            $game->id,
        ), $game);
    }

    /**
     * `remote: false` once the player's daily cap of challenge notifications
     * off the page is reached (DailyChallenges): bell and page only.
     */
    public function challengeReceived(ChessChallenge $challenge, bool $remote = true): void
    {
        $player = $challenge->challenged;
        $locale = $this->locale($player);
        $color = match ($challenge->color) {
            'white' => __('Black', [], $locale),
            'black' => __('White', [], $locale),
            default => __('a random colour', [], $locale),
        };

        // One line, no links, at most 140 characters (PlainText); the DM cleans the rest.
        $message = PlainText::line((string) $challenge->message, 140);

        $this->notifier->send($player, NotificationKind::Challenge, new Notice(
            __(':name challenges you to daily chess', ['name' => $challenge->challenger->displayName()], $locale),
            $message !== ''
                ? __('":message" · Casual, you play :color.', ['message' => $message, 'color' => $color], $locale)
                : __('Casual, you play :color. Open for :hours hours.', ['color' => $color, 'hours' => (int) config('esports.chess.challenge_hours')], $locale),
            route('me.correspondence'),
            null,
            __('Answer', [], $locale),
        ), remote: $remote, sender: $challenge->challenger);
    }

    /**
     * The daily challenge was accepted: the challenger's game is on.
     */
    public function gameStarted(ChessGame $game, User $challenger): void
    {
        $locale = $this->locale($challenger);
        $opponent = $game->opponentOf($challenger);
        $white = $game->colorOf($challenger) === 'w';

        $this->notifier->send($challenger, NotificationKind::GameStarted, new Notice(
            __(':name accepted your daily challenge', ['name' => $opponent?->displayName() ?? ''], $locale),
            $white
                ? __('Daily chess :number · you play White, your move.', ['number' => $game->number()], $locale)
                : __('Daily chess :number · you play Black, their move.', ['number' => $game->number()], $locale),
            route('games.show', $game),
            $game->id,
            __('Open game', [], $locale),
        ), $game);
    }

    /**
     * The blitz queue paired these two: both are told, wherever they are.
     */
    public function matchFound(ChessGame $game): void
    {
        foreach ([$game->white, $game->black] as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::MatchFound, new Notice(
                __('Opponent found: :name', ['name' => $game->opponentOf($player)?->displayName() ?? ''], $locale),
                __(':control · you play :color · :number', [
                    'control' => $this->control($game),
                    'color' => $game->colorOf($player) === 'w' ? __('White', [], $locale) : __('Black', [], $locale),
                    'number' => $game->number(),
                ], $locale),
                route('games.show', $game),
                $game->id,
                __('Play now', [], $locale),
            ), $game, remote: false);
        }
    }

    public function inviteReceived(ChessInvite $invite): void
    {
        $player = $invite->invitee;
        $locale = $this->locale($player);

        $this->notifier->send($player, NotificationKind::Invite, new Notice(
            __(':name invites you', ['name' => $invite->inviter->displayName()], $locale),
            __('Blitz 5+3 · Casual · colours drawn at random', [], $locale),
            route('chess.lobby'),
            null,
            __('Answer', [], $locale),
        ), remote: false);
    }

    public function inviteAccepted(ChessInvite $invite, ChessGame $game): void
    {
        $player = $invite->inviter;
        $locale = $this->locale($player);

        $this->notifier->send($player, NotificationKind::InviteAccepted, new Notice(
            __(':name accepted your invite', ['name' => $invite->invitee->displayName()], $locale),
            __(':control · you play :color · :number', [
                'control' => $this->control($game),
                'color' => $game->colorOf($player) === 'w' ? __('White', [], $locale) : __('Black', [], $locale),
                'number' => $game->number(),
            ], $locale),
            route('games.show', $game),
            $game->id,
            __('Play now', [], $locale),
        ), $game, remote: false);
    }

    /**
     * Both players of an ended game. Daily games go out on every channel,
     * live games only in the app (both players were at the board).
     */
    public function gameOver(ChessGame $game): void
    {
        $game->refresh();
        $daily = $game->isCorrespondence();

        foreach ([$game->white, $game->black] as $player) {
            $locale = $this->locale($player);
            $color = $game->colorOf($player);
            $won = $game->result !== null && $game->result !== '1/2-1/2' && ($game->result === '1-0') === ($color === 'w');
            $outcome = match (true) {
                $game->result === null => 'aborted',
                $game->result === '1/2-1/2' => 'draw',
                $won => 'win',
                default => 'loss',
            };
            $url = route('games.show', $game);

            if ($won && $game->end_reason === ChessEndReason::Resignation) {
                $this->notifier->send($player, NotificationKind::OpponentResigned, new Notice(
                    __(':name resigned', ['name' => $game->opponentOf($player)?->displayName() ?? ''], $locale),
                    __(':mode :number · you won', ['mode' => $daily ? __('Daily chess', [], $locale) : __('Blitz', [], $locale), 'number' => $game->number()], $locale),
                    $url,
                    $game->id,
                    __('See the game', [], $locale),
                ), $game, remote: $daily);

                continue;
            }

            $label = match ($outcome) {
                'aborted' => __('aborted, nothing counts', [], $locale),
                'draw' => __('draw', [], $locale),
                'win' => __('you won', [], $locale),
                default => __('you lost', [], $locale),
            };

            $this->notifier->send($player, NotificationKind::GameOver, new Notice(
                $daily
                    ? __('Daily chess :number is over', ['number' => $game->number()], $locale)
                    : __('Blitz :number is over', ['number' => $game->number()], $locale),
                __(':outcome · :reason', [
                    'outcome' => $label,
                    'reason' => __(($game->end_reason ?? ChessEndReason::Aborted)->label(), [], $locale),
                ], $locale),
                $url,
                $game->id,
                __('See the game', [], $locale),
                $outcome === 'aborted' ? 'ping' : $outcome,
            ), $game, remote: $daily);
        }
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }

    private function control(ChessGame $game): string
    {
        return intdiv((int) $game->initial_ms, 60_000).'+'.intdiv((int) $game->increment_ms, 1000);
    }

    private function moveLabel(int $ply, string $san, string $locale): string
    {
        return intdiv($ply + 1, 2).($ply % 2 === 1 ? '. ' : '… ').SanNotation::display($san, $locale);
    }

    private function deadline(ChessGame $game, User $user): string
    {
        $deadline = Carbon::createFromTimestampMs((int) $game->deadline_ms)->setTimezone($user->timezone ?? (string) config('app.timezone'));
        $deadline->locale($this->locale($user));

        return $deadline->isoFormat('ddd YYYY-MM-DD HH:mm');
    }
}
