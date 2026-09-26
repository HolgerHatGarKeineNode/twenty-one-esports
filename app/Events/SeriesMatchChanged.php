<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A Rocket League series changed (challenge, answer, live score, report,
 * answer to the report, admin decision), sent on the private user channel
 * of every player of both lineups and both clan owners, so their match dock
 * refreshes at once instead of on its next poll (P5f note, P7c). It carries
 * only the match number and status: the dock reads everything else from the
 * server, as it does on any other event.
 */
final class SeriesMatchChanged implements ShouldBroadcastNow
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public array $userIds, public int $number, public string $status) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id): PrivateChannel => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'series.changed';
    }

    /**
     * @return array{number: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['number' => $this->number, 'status' => $this->status];
    }
}
