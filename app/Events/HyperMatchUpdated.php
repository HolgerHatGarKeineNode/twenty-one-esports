<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A Hyperbitcoinization match after a change (a player's action, a bot turn, a turn the timer ended, a
 * takeover), pushed to the seated players on the private `hyper.{ulid}` channel and to spectators on the
 * public `hyper.{ulid}.watch` channel. Both get the table's view (HyperView without a seat): nobody's
 * cards. A seat's own secrets go to its private `hyper.{ulid}.seat.{n}` channel (HyperHandUpdated).
 *
 * `events` are the events of the plies `from_ply + 1 .. ply`; null with `truncated: true` when they would
 * not fit into one Reverb message (10 kB), and the page reads them from the events endpoint instead.
 */
final class HyperMatchUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $payload  HyperMatches::broadcastPayload()
     */
    public function __construct(public string $match, public array $payload) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('hyper.'.$this->match),
            new Channel('hyper.'.$this->match.'.watch'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hyper.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
