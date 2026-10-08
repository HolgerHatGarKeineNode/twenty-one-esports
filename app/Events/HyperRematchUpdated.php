<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A rematch of a finished Hyperbitcoinization match moved on: a player said yes, or it started
 * (App\Support\Hyper\HyperLobby::rematch()). Sent where the old match's pages listen: the players'
 * private `hyper.{ulid}` and the spectators' public `hyper.{ulid}.watch`. With a `url` every open page of
 * the old match goes to the new one.
 */
final class HyperRematchUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array{table: string, ready: list<int>, waiting: list<int>, url: string|null}  $payload
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
        return 'hyper.rematch';
    }

    /**
     * @return array{table: string, ready: list<int>, waiting: list<int>, url: string|null}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
