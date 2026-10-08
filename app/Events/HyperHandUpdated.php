<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One seat's secrets after a change of its hand: the cards it holds now and, in its own view, the events
 * the table saw redacted (the card it drew, Lagarde's next card). Only on the seat's private
 * `hyper.{ulid}.seat.{n}` channel, which only that seat's player may join (routes/channels.php).
 */
final class HyperHandUpdated implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $hand
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public string $match, public int $seat, public int $ply, public array $hand, public array $events) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('hyper.'.$this->match.'.seat.'.$this->seat)];
    }

    public function broadcastAs(): string
    {
        return 'hyper.hand';
    }

    /**
     * @return array{match: string, seat: int, ply: int, hand: list<string>, events: list<array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return ['match' => $this->match, 'seat' => $this->seat, 'ply' => $this->ply, 'hand' => $this->hand, 'events' => $this->events];
    }
}
