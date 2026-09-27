<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A casual 1v1 invite (P23) was sent, accepted, withdrawn or declined: both
 * players' pages reload their invites. Sent on both private user channels.
 */
final class SeriesInviteChanged implements ShouldBroadcastNow
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
        return 'series.invite';
    }

    /**
     * @return array{inviteId: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['inviteId' => $this->inviteId, 'status' => $this->status];
    }
}
