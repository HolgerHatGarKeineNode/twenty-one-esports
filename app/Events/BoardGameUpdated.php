<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The state of a board game (not chess) after any change (move, clock, draw
 * offer, end), pushed to both players on the private `board.{id}` channel
 * and to spectators on the public `board.{id}.watch` channel.
 *
 * It carries the pieces, both clocks, the legal moves and the last move, not
 * the whole move list (Reverb caps a message at 10 kB). A client whose ply
 * does not follow on from the last one asks the server for the full snapshot.
 */
final class BoardGameUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $state  BoardGameService::snapshot() without the move list
     */
    public function __construct(public int $gameId, public array $state) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('board.'.$this->gameId),
            new Channel('board.'.$this->gameId.'.watch'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->state;
    }
}
