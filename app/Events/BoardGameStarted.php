<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A board game (not chess) was created for these players: a page listening
 * on their own private user channel moves to it. Sent on each player's
 * channel; the queue and invites that start games come with P5.
 */
final class BoardGameStarted implements ShouldBroadcastNow
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public int $gameId, public string $url, public array $userIds) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'board.game-started';
    }

    /**
     * @return array{gameId: int, url: string}
     */
    public function broadcastWith(): array
    {
        return ['gameId' => $this->gameId, 'url' => $this->url];
    }
}
