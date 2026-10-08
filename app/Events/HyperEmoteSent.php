<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A quick emote at a Hyperbitcoinization table (plan "Hyperbitcoinization", Ansatz 6): a sticker or a
 * soundboard clip, sent by a seated player, to the players and the spectators. Fleeting: never stored.
 */
final class HyperEmoteSent implements ShouldBroadcastNow
{
    /**
     * @param  'sticker'|'clip'  $kind
     */
    public function __construct(public string $match, public int $seat, public string $kind, public string $emote) {}

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
        return 'hyper.emote';
    }

    /**
     * @return array{match: string, seat: int, kind: string, emote: string}
     */
    public function broadcastWith(): array
    {
        return ['match' => $this->match, 'seat' => $this->seat, 'kind' => $this->kind, 'emote' => $this->emote];
    }
}
