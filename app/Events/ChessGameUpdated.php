<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The state of a game after any change (move, clock, draw offer, end,
 * rematch), pushed to both players on the private `game.{id}` channel and to
 * spectators on the public `game.{id}.watch` channel.
 *
 * It carries the new position, both clocks and the last move, not the whole
 * move list (Reverb caps a message at 10 kB). A client whose ply does not
 * follow on from the last one asks the server for the full snapshot.
 */
final class ChessGameUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $state  ChessGameService::snapshot() without the move list
     */
    public function __construct(public int $gameId, public array $state) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('game.'.$this->gameId),
            new Channel('game.'.$this->gameId.'.watch'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'game.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->state;
    }
}
