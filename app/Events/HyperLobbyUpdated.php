<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The Hyperbitcoinization lobby changed (a table opened, a seat was taken or freed, a faction chosen, a
 * table started or closed), on the public `hyper.lobby` channel. It carries nothing: what a table shows
 * is public, and the lobby page renders again (App\Support\Hyper\HyperLobby).
 */
final class HyperLobbyUpdated implements ShouldBroadcastNow
{
    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('hyper.lobby')];
    }

    public function broadcastAs(): string
    {
        return 'hyper.lobby';
    }

    /**
     * @return array<string, never>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
