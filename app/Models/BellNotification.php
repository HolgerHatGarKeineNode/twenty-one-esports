<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * The `notifications` table as the scheduler prunes it (performance plan P2,
 * S11). The app reads it through the users' `notifications()` relation
 * (Laravel's DatabaseNotification); this class only adds the prune rule.
 *
 * The only reader is the bell: the newest LIMIT of a player and the count of
 * the unread ones (<livewire:notification-bell>). So a row goes once it is
 * read, older than KEEP_DAYS, and not among the player's newest KEEP: an
 * unread notice stays however old, and a quiet player's bell still lists
 * what it listed before.
 */
class BellNotification extends DatabaseNotification
{
    use MassPrunable;

    /** Read notifications younger than this stay. */
    public const KEEP_DAYS = 90;

    /** The newest of each player stay whatever their age: the bell's list (⚡notification-bell LIMIT). */
    public const KEEP = 20;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $ranked = DB::table('notifications')
            ->select(['id', 'read_at', 'created_at'])
            ->selectRaw('row_number() over (partition by notifiable_type, notifiable_id order by created_at desc, id desc) as place');

        return static::query()->whereIn('id', DB::query()->fromSub($ranked, 'ranked')
            ->where('place', '>', self::KEEP)
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->select('id'));
    }
}
