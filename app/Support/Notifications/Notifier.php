<?php

namespace App\Support\Notifications;

use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\ChessGame;
use App\Models\User;

/**
 * Sends one notification over the channels the player chose (ChessSettings):
 * browser push to each subscribed browser, and a NIP-17 DM from the league's
 * notification key. Each delivery is its own queued job, so a slow push
 * service or relay never holds up a move.
 *
 * Per daily game a player can override the channel for "the opponent moved"
 * (`dm`, `push`, or `here` = only on the page) and switch off the deadline
 * reminder (ChessCorrespondence, "Tell me when …" / "Remind me when …").
 */
final class Notifier
{
    /**
     * @param  'your_move'|'reminder'|'challenge'|'game_over'  $trigger
     * @return list<'push'|'dm'> the channels it went out on
     */
    public function send(User $user, string $trigger, Notice $notice, ?ChessGame $game = null): array
    {
        $settings = $user->chessSettings();

        if (! $settings->wants($trigger)) {
            return [];
        }

        $channels = array_values(array_filter([$settings->push ? 'push' : null, $settings->dm ? 'dm' : null]));

        if ($game !== null && ($color = $game->colorOf($user)) !== null) {
            if ($trigger === 'reminder' && ! ($color === 'w' ? $game->white_remind : $game->black_remind)) {
                return [];
            }

            $choice = $color === 'w' ? $game->white_notify : $game->black_notify;

            if ($trigger === 'your_move' && $choice !== null) {
                $channels = match ($choice) {
                    'dm' => ['dm'],
                    'push' => ['push'],
                    default => [],
                };
            }
        }

        $sent = [];

        if (in_array('push', $channels, true) && WebPush::fromConfig()->isConfigured()) {
            foreach ($user->pushSubscriptions()->get() as $subscription) {
                SendWebPush::dispatch($subscription, $notice->toPushPayload());
                $sent['push'] = 'push';
            }
        }

        if (in_array('dm', $channels, true) && NotificationDm::fromConfig()->isConfigured()) {
            SendNostrDm::dispatch($user, $notice->toDmText(), $notice->match);
            $sent['dm'] = 'dm';
        }

        return array_values($sent);
    }
}
