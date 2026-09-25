<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A new notification for this player (P5c), sent on their private user
 * channel, which every logged-in page listens to (resources/js/alerts.js):
 * the page shows the toast, plays the sound, flashes the tab title and, with
 * the tab hidden, raises a desktop notification.
 */
final class UserNotified implements ShouldBroadcastNow
{
    /**
     * @param  array{id: string, kind: string, title: string, body: string, url: string, match: int|null, action: string|null, sound: string, tone: string, redirect: bool}  $alert
     */
    public function __construct(public int $userId, public array $alert) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'user.notified';
    }

    /**
     * @return array{id: string, kind: string, title: string, body: string, url: string, match: int|null, action: string|null, sound: string, tone: string, redirect: bool}
     */
    public function broadcastWith(): array
    {
        return $this->alert;
    }
}
