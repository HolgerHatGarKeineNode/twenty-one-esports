<?php

namespace App\Support\StreamBot;

use App\Support\TwentyOne\PublishResult;
use App\Support\TwentyOne\RelayPublisher;

/**
 * Sends the bot's signed events to the stream relays, all at once under one
 * deadline (`twentyone.nostr.publish_timeout_seconds`), with the stream
 * daemon's publisher: a relay counts only with an `OK true` for this id.
 * Its own class so tests can stand in for the network.
 */
class StreamBotPublisher
{
    public function __construct(private RelayPublisher $publisher) {}

    /**
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}  $event
     * @param  list<string>  $relays
     * @return array<string, PublishResult>
     */
    public function publish(array $event, array $relays): array
    {
        return $this->publisher->publish($event, $relays, (float) config('twentyone.nostr.publish_timeout_seconds', 5));
    }
}
