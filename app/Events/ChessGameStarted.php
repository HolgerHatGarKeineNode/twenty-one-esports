<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A game was created for these players (queue pairing, accepted invite,
 * accepted rematch): their open lobby or game page moves to it. Sent on each
 * player's own private user channel.
 */
final class ChessGameStarted implements ShouldBroadcastNow
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
        return 'chess.game-started';
    }

    /**
     * @return array{gameId: int, url: string}
     */
    public function broadcastWith(): array
    {
        return ['gameId' => $this->gameId, 'url' => $this->url];
    }
}
