<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether a player is on the site right now: a page of theirs was visible
 * within `esports.notifications.on_site_seconds`. Every logged-in page pings
 * `presence.ping` once a minute while it is visible (resources/js/onSite.js);
 * a hidden tab stops pinging, so a tab left open on another device does not
 * count for long.
 *
 * Why not the `online` presence channel (PresenceLookup): a tab stays on it
 * while hidden, so a forgotten desktop tab would silence every push to the
 * phone.
 *
 * The Notifier sends nothing off the site to a player who is on it (the bell
 * and the toast reach them). Fails open: no ping, or a cache it cannot read,
 * counts as away, because a missed notification costs more than one extra.
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
