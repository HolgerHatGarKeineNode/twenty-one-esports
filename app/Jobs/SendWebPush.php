<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\Notifications\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one browser push. A push service that no longer knows the
 * subscription (404/410) makes WebPush delete it; other failures are not
 * retried: a late "your move" is worth less than none.
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public PushSubscription $subscription, public array $payload)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        WebPush::fromConfig()->send($this->subscription, $this->payload);
    }
}
