<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The referee of a live Proof of Pong match decided something (plan "Proof of Pong", P2): a serve, a hit, a goal, a
 * pause, the end, a rematch offer. Both players' pages take the snapshot (App\Support\Pong\PongMatches::snapshot()) as
 * the truth and re-sync their rally from it. Sent on the match's presence channel `pong.{ulid}`, where the pages also
 * stream their paddles to each other (client events) and see each other come and go.
 */
final class PongMatchUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(public string $ulid, public array $snapshot) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('pong.'.$this->ulid);
    }

    public function broadcastAs(): string
    {
        return 'pong.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
