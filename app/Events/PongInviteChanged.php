<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * An invite to a live Proof of Pong match (plan "Proof of Pong", P2) was sent, accepted, withdrawn or declined: both
 * players' Proof of Pong lobbies reload it. Sent on both players' private user channels, as BoardInviteChanged.
 */
final class PongInviteChanged implements ShouldBroadcastNow
{
    public function __construct(public int $inviteId, public string $status, public int $inviterId, public int $inviteeId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->inviterId),
            new PrivateChannel('App.Models.User.'.$this->inviteeId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pong.invite';
    }

    /**
     * @return array{inviteId: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['inviteId' => $this->inviteId, 'status' => $this->status];
    }
}
