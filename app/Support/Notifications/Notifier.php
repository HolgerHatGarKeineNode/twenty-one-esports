<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Events\UserNotified;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\ChessGame;
use App\Models\User;
use App\Notifications\LeagueNotification;
use App\Support\Chess\Broadcasts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one notification over the channels the player chose (ChessSettings):
 * browser push to each subscribed browser, and a NIP-17 DM from the league's
 * notification key. Each delivery is its own queued job, so a slow push
 * service or relay never holds up a move.
 *
 * Per daily game a player can override the channel for "the opponent moved"
 * (`dm`, `push`, or `here` = only on the page) and switch off the deadline
 * reminder (ChessCorrespondence, "Tell me when …" / "Remind me when …").
 *
 * In the app (P5c): every notification the player has switched on is also
 * stored for the bell and pushed to their open pages (UserNotified), once the
 * surrounding transaction commits. `remote: false` keeps an event in the app
 * only: live events (opponent found, blitz invite) mean nothing an hour later
 * in an inbox. Storing and pushing fail open: a broken notification never
 * undoes the game that caused it.
 */
final class Notifier
{
    /**
     * @return list<'push'|'dm'> the remote channels it went out on
     */
    public function send(User $user, NotificationKind $kind, Notice $notice, ?ChessGame $game = null, bool $remote = true): array
    {
        $settings = $user->chessSettings();
        $trigger = $kind->value;

        if (! $settings->wants($trigger)) {
            return [];
        }

        if ($trigger === 'reminder' && $game !== null && ($color = $game->colorOf($user)) !== null
            && ! ($color === 'w' ? $game->white_remind : $game->black_remind)) {
            return [];
        }

        $this->inApp($user, $kind, $notice);

        if (! $remote) {
            return [];
        }

        $channels = array_values(array_filter([$settings->push ? 'push' : null, $settings->dm ? 'dm' : null]));

        if ($game !== null && ($color = $game->colorOf($user)) !== null) {
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

    /**
     * Store the bell entry and push it to the player's open pages.
     */
    private function inApp(User $user, NotificationKind $kind, Notice $notice): void
    {
        DB::afterCommit(function () use ($user, $kind, $notice): void {
            try {
                // Our id, not the sender's: it sends a clone and would keep the id to itself.
                $notification = new LeagueNotification($kind, $notice);
                $notification->id = (string) Str::uuid();
                $user->notifyNow($notification);
            } catch (Throwable $exception) {
                report($exception);

                return;
            }

            Broadcasts::send(new UserNotified($user->id, [
                'id' => (string) $notification->id,
                ...$notification->toArray($user),
                'tone' => $kind->tone(),
                'redirect' => $kind->redirects(),
            ]));
        });
    }
}
