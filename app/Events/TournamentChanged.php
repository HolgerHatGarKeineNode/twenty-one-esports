<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something on a tournament's bracket moved (a result came in or was
 * corrected, a round closed, the next round was paired, the tournament
 * ended), sent on the public `tournament.{id}` channel for the TV view
 * (P19). It carries only the id and what moved: the TV reads everything
 * else from the server, and polls as well, so a push it missed costs it at
 * most one poll interval. Sent through App\Support\Chess\Broadcasts after
 * the commit.
 */
final class TournamentChanged implements ShouldBroadcastNow
{
    public function __construct(public int $tournamentId, public string $reason) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('tournament.'.$this->tournamentId)];
    }

    public function broadcastAs(): string
    {
        return 'tournament.changed';
    }

    /**
     * @return array{id: int, reason: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->tournamentId, 'reason' => $this->reason];
    }
}
