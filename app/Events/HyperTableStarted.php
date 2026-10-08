<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A lobby table of these players became a Hyperbitcoinization match (App\Support\Hyper\HyperLobby): sent on
 * each player's own channel, so a lobby page open anywhere opens the match in a new tab. Bots hear nothing.
 */
final class HyperTableStarted implements ShouldBroadcastNow
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public string $table, public string $url, public array $userIds) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id): PrivateChannel => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'hyper.table-started';
    }

    /**
     * @return array{table: string, url: string}
     */
    public function broadcastWith(): array
    {
        return ['table' => $this->table, 'url' => $this->url];
    }
}
