<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something happened in the league that an OBS overlay may show (plan "OBS-Broadcast-Overlays", P2): a win in any
 * game, a checked highscore, a rank-up, a tournament sign-up, a closed tournament round, a champion, a payout. Sent
 * on the public `league.feed` channel by App\Support\Broadcast\LeagueFeed after the commit, throttled; an overlay
 * that missed it catches up from its snapshot poll (`/broadcast/{token}/snapshot.json`, every 20 s).
 *
 * Public and minimal: what happened, the game, names as the site shows them publicly and numbers. Never a key, an
 * email, a Lightning address or a game account.
 */
final class LeagueFeedEvent implements ShouldBroadcastNow
{
    /**
     * @param  list<array<string, scalar|list<string>|null>>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('league.feed')];
    }

    public function broadcastAs(): string
    {
        return 'league.feed';
    }

    /**
     * @return array{items: list<array<string, scalar|list<string>|null>>}
     */
    public function broadcastWith(): array
    {
        return ['items' => $this->items];
    }
}
