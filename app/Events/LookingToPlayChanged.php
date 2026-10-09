<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A player switched "Looking to play" on or off; everyone on the global
 * presence channel updates that player's row in the online list.
 *
 * `at`: server ms, taken after the switch was saved (it is made after the save). A page orders the push against a
 * member's join data by it (resources/js/echo.js): a join whose state was read before the switch is older even when
 * it arrives later (plan "Proof of Pong", P6, measured in tests/Browser/OnlineLookingTest.php).
 */
final class LookingToPlayChanged implements ShouldBroadcastNow
{
    public float $at;

    public function __construct(public int $userId, public ?string $lookingToPlay)
    {
        $this->at = round(microtime(true) * 1000, 3);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('online');
    }

    public function broadcastAs(): string
    {
        return 'presence.looking';
    }

    /**
     * @return array{id: int, looking: string|null, at: float}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->userId, 'looking' => $this->lookingToPlay, 'at' => $this->at];
    }
}
