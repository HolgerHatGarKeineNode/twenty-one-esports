<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * An admin muted, banned or let a Nostr key back (App\Support\Moderation\SiteModeration),
 * sent on the public `moderation` channel so every open chat hides or shows
 * the key's messages without a reload (resources/js/siteHidden.js). It says
 * only whether the key is hidden now, never whether it was muted or banned:
 * nobody but an admin learns that. A page that missed it gets the list with
 * its next load. Sent through App\Support\Chess\Broadcasts after the commit.
 */
final class SiteModerationChanged implements ShouldBroadcastNow
{
    public function __construct(public string $pubkey, public bool $hidden) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('moderation')];
    }

    public function broadcastAs(): string
    {
        return 'moderation.changed';
    }

    /**
     * @return array{pubkey: string, hidden: bool}
     */
    public function broadcastWith(): array
    {
        return ['pubkey' => $this->pubkey, 'hidden' => $this->hidden];
    }
}
