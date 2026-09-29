<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether a player is on the site right now: a page of theirs was visible
 * within `esports.notifications.on_site_seconds` (75 s). Every logged-in
 * page pings `presence.ping` every 30 s while it is visible
 * (resources/js/onSite.js); a hidden or closed page stops, so leaving the
 * site lets push and DM go out again at most 75 s later. There is no
 * "left" request at the moment a page goes: see onSite.js for the measured
 * reason.
 *
 * Why not the `online` presence channel (PresenceLookup): a tab stays on it
 * while hidden, so a forgotten desktop tab would silence every push to the
 * phone. A visible tab with nobody at it still counts as on the site; that
 * is accepted, and the correspondence deadline reminder goes out anyway
 * (NotificationKind::remoteWhileOnSite()).
 *
 * A notification for a player who is on the site is not sent by push or DM
 * at all, not held back for later: the bell and the toast reach them
 * (Notifier). Fails open: no ping, or a cache it cannot read, counts as
 * away, because a missed notification costs more than one extra.
 */
final class OnSite
{
    private const KEY = 'on-site:';

    public function seen(User $user): void
    {
        Cache::put(self::KEY.$user->id, now()->getTimestamp(), now()->addSeconds($this->window()));
    }

    public function isOnSite(User $user): bool
    {
        try {
            $seen = Cache::get(self::KEY.$user->id);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return is_int($seen) && now()->getTimestamp() - $seen < $this->window();
    }

    private function window(): int
    {
        return max(1, (int) config('esports.notifications.on_site_seconds'));
    }
}
