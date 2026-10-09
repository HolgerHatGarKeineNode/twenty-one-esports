<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A live Proof of Pong match was created for these players (plan "Proof of Pong", P2): an accepted invite. The
 * Proof of Pong lobby of each player opens the match. Sent on each player's private user channel.
 *
 * @see BoardGameStarted the board games' counterpart
 */
final class PongMatchStarted implements ShouldBroadcastNow
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public string $ulid, public string $url, public array $userIds) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'pong.match-started';
    }

    /**
     * @return array{ulid: string, url: string}
     */
    public function broadcastWith(): array
    {
        return ['ulid' => $this->ulid, 'url' => $this->url];
    }
}
