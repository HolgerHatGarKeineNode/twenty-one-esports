<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A player switched "Looking to play" on or off; everyone on the global
 * presence channel updates that player's row in the online list.
 */
final class LookingToPlayChanged implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public ?string $lookingToPlay) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('online');
    }

    public function broadcastAs(): string
    {
        return 'presence.looking';
    }

    /**
     * @return array{id: int, looking: string|null}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->userId, 'looking' => $this->lookingToPlay];
    }
}
