<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The opt-out every notification DM ends with: a signed link that works
 * without logging in, because the recipient may never have logged in. It
 * has no expiry, since a DM stays in the inbox for good.
 *
 * The link opens a page (NotificationDmOptOutController); the switch itself
 * is a POST from that page, so a Nostr client that fetches a link preview
 * never turns anything off.
 */
final class NotificationDmOptOut
{
    public const SCOPES = ['all', 'challenge'];

    /**
     * Signed over path and query only (route middleware `signed:relative`),
     * so the signature holds whatever host or scheme a proxy puts in front.
     */
    public static function url(User $user, ?string $done = null): string
    {
        return url(URL::signedRoute('notifications.dm-off', array_filter(['user' => $user->id, 'done' => $done]), absolute: false));
    }

    /**
     * The last line of a DM, in the recipient's language, on config('app.url').
     */
    public static function line(User $user): string
    {
        return __('Turn off these DMs: :url', ['url' => Notice::onApp(self::url($user))], $user->locale ?? (string) config('app.locale'));
    }

    /**
     * `all`: no DM at all (dm false). `challenge`: the challenge trigger off,
     * so challenges no longer notify on any channel.
     */
    public static function apply(User $user, string $scope): void
    {
        $settings = $user->chessSettings()->toArray();

        if ($scope === 'all') {
            $settings['dm'] = false;
        } else {
            $settings['triggers']['challenge'] = false;
        }

        $user->forceFill(['chess_settings' => $settings])->save();
    }
}
