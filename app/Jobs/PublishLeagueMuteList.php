<?php

namespace App\Jobs;

use App\Support\Moderation\LeagueMuteList;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A new version of the league's public mute list (NIP-51 kind 10000), after
 * an admin muted, banned or let a key back (SiteModeration). While a run is
 * queued, further changes coalesce into it (unique until it starts); it
 * reads the table when it runs, so it signs the state after all of them.
 * Dispatched after the commit. Tried again on a failure of its own: no
 * relay answered the read of the newest list (MuteListUnreadable, fail
 * closed), the writer's lock, the database. Relay refusals of the publish
 * never fail it (PublishNostrEvent records them, `nostr:republish` retries),
 * and the hourly `esports:mute-list` run reconciles whatever is left.
 */
class PublishLeagueMuteList implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct()
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'league-mute-list';
    }

    public function handle(LeagueMuteList $list): void
    {
        $list->publish();
    }
}
