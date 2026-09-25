<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Notifications\NotificationDm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one NIP-17 notification DM from the league's notification key to
 * the chat relays (App\Support\Notifications\NotificationDm).
 */
class SendNostrDm implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public string $text, public ?int $match = null)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        NotificationDm::fromConfig()->send($this->user, $this->text, $this->match);
    }
}
