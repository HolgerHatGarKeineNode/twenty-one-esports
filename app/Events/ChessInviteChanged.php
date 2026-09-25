<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A blitz invite was sent, withdrawn, declined or expired: both lobbies
 * reload their invite list. Sent on both players' private user channels.
 */
final class ChessInviteChanged implements ShouldBroadcastNow
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
        return 'chess.invite';
    }

    /**
     * @return array{inviteId: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['inviteId' => $this->inviteId, 'status' => $this->status];
    }
}
