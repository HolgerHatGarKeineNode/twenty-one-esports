<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Events\UserNotified;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\ChatMute;
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
 * only: live events of players at the board (invite accepted, a blitz game
 * over) mean nothing an hour later in an inbox. Opponent found and blitz
 * invite do go out: they reach a player who is away. Storing and pushing fail open: a broken notification never
 * undoes the game that caused it.
 *
 * Nostr DM (ChessSettings::dmFor): on by default for the kinds an offline
 * player has to act on. Every DM ends with a signed one-click opt-out link
 * (NotificationDmOptOut), because the recipient may never have logged in.
 * Never a DM: "your move" outside a daily game (a live game's players are at
 * the board), and anything from a sender the recipient muted (ChatMute).
 */
final class Notifier
{
    /**
     * @return list<'push'|'dm'> the remote channels it went out on
     */
    public function send(User $user, NotificationKind $kind, Notice $notice, ?ChessGame $game = null, bool $remote = true, ?User $sender = null): array
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

        $channels = array_values(array_filter([$settings->push ? 'push' : null, $settings->dmFor($trigger) ? 'dm' : null]));

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

        // Fail closed: "your move" is a DM only in a daily game, whatever was chosen.
        if ($kind === NotificationKind::YourMove && ($game === null || ! $game->isCorrespondence())) {
            $channels = array_values(array_diff($channels, ['dm']));
        }

        if ($sender !== null && ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $sender->pubkey)->exists()) {
            $channels = array_values(array_diff($channels, ['dm']));
        }

        $sent = [];

        if (in_array('push', $channels, true) && WebPush::fromConfig()->isConfigured()) {
            foreach ($user->pushSubscriptions()->get() as $subscription) {
                SendWebPush::dispatch($subscription, $notice->toPushPayload());
                $sent['push'] = 'push';
            }
        }

        if (in_array('dm', $channels, true) && NotificationDm::fromConfig()->isConfigured()) {
            SendNostrDm::dispatch($user, $notice->toDmText(NotificationDmOptOut::line($user)), $notice->match);
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
