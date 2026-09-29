<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Events\UserNotified;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\BoardGame;
use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\NotificationDigestItem;
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
 * (`push`, or `here` = only on the page) and switch off the deadline
 * reminder (ChessCorrespondence, "Tell me when …" / "Remind me when …").
 * A stored `dm` from before "your move" lost its DM counts as `here`: the
 * player never chose push.
 *
 * In the app (P5c): every notification the player has switched on is also
 * stored for the bell and pushed to their open pages (UserNotified), once the
 * surrounding transaction commits. `remote: false` keeps an event in the app
 * only. Storing and pushing fail open: a broken notification never undoes
 * the game that caused it.
 *
 * Off the site, in this order (audit 2026-09-30, "viel zu viele" DMs):
 * - the kind decides what may go out at all (NotificationKind::pushAllowed(),
 *   dmAllowed()), whatever the switches, the digest or a game's choice say:
 *   no DM for anything live, and "your move" never by DM;
 * - a player on the site gets the bell and the toast only (OnSite), except
 *   for the correspondence deadline reminder;
 * - "your move" not while the player is at the board, and at most once per
 *   game and hour (YourMoveThrottle).
 *
 * Nostr DM (ChessSettings::dmFor): every DM ends with a signed one-click
 * opt-out link (NotificationDmOptOut), because the recipient may never have
 * logged in. Never a DM from a sender the recipient muted (ChatMute).
 *
 * A correspondence board game (plan "Mühle und Dame", P8) counts as a daily
 * game for "your move"; it has no per-game channel or reminder switch, so
 * the account settings decide.
 */
final class Notifier
{
    /**
     * @return list<'push'|'dm'|'digest'> the remote channels it went out on ('digest': a DM that waits for the daily digest)
     */
    public function send(User $user, NotificationKind $kind, Notice $notice, ChessGame|BoardGame|null $game = null, bool $remote = true, ?User $sender = null): array
    {
        $settings = $user->chessSettings();
        $trigger = $kind->value;

        if (! $settings->wants($trigger)) {
            return [];
        }

        if ($trigger === 'reminder' && $game instanceof ChessGame && ($color = $game->colorOf($user)) !== null
            && ! ($color === 'w' ? $game->white_remind : $game->black_remind)) {
            return [];
        }

        $this->inApp($user, $kind, $notice);

        if (! $remote) {
            return [];
        }

        $channels = array_values(array_filter([$settings->push ? 'push' : null, $settings->dmFor($trigger) ? 'dm' : null]));

        if ($game instanceof ChessGame && ($color = $game->colorOf($user)) !== null) {
            $choice = $color === 'w' ? $game->white_notify : $game->black_notify;

            if ($kind === NotificationKind::YourMove && $choice !== null) {
                $channels = $choice === 'push' ? ['push'] : [];
            }
        }

        // A DM only where the kind allows one (ChessSettings::dmFor, NotificationKind::dmAllowed()); push likewise.
        $channels = array_values(array_filter($channels, fn (string $channel): bool => $channel !== 'push' || $kind->pushAllowed($game)));

        if ($sender !== null && ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $sender->pubkey)->exists()) {
            $channels = array_values(array_diff($channels, ['dm']));
        }

        if ($channels !== [] && ! $kind->remoteWhileOnSite() && app(OnSite::class)->isOnSite($user)) {
            return [];
        }

        if ($kind === NotificationKind::YourMove && $game !== null && $channels !== [] && ! app(YourMoveThrottle::class)->allowsRemote($user, $game)) {
            return [];
        }

        $sent = [];

        if (in_array('push', $channels, true) && WebPush::fromConfig()->isConfigured()) {
            foreach ($user->pushSubscriptions()->get() as $subscription) {
                SendWebPush::dispatch($subscription, $notice->toPushPayload());
                $sent['push'] = 'push';
            }
        }

        if (in_array('dm', $channels, true) && NotificationDm::fromConfig()->isConfigured()) {
            if ($settings->digestFor($trigger)) {
                // P45: waits for the daily digest (DmDigest), in the same transaction as its cause.
                NotificationDigestItem::query()->create([
                    'user_id' => $user->id,
                    'kind' => $trigger,
                    'title' => mb_substr($notice->title, 0, 200),
                    'body' => mb_substr($notice->body, 0, 500),
                    'url' => mb_substr(Notice::onApp($notice->url), 0, 500),
                    'match' => $notice->match,
                ]);
                $sent['dm'] = 'digest';
            } else {
                SendNostrDm::dispatch($user, $notice->toDmText(NotificationDmOptOut::line($user)), $notice->match);
                $sent['dm'] = 'dm';
            }
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
