<?php

namespace App\Jobs;

use App\Models\NostrEvent;
use App\Support\Nostr\RelayPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publishes one accepted signed event to the league's relays. Dispatched
 * after the database transaction commits, so a relay never sees an event
 * the league rolled back.
 */
class PublishNostrEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public NostrEvent $event)
    {
        $this->afterCommit();
    }

    public function handle(RelayPublisher $publisher): void
    {
        $publisher->publish($this->event);
    }
}
