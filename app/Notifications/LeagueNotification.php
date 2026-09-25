<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Support\Notifications\Notice;
use Illuminate\Notifications\Notification;

/**
 * One entry in the player's notification bell (P5c), stored with Laravel's
 * database notifications. Only the store goes through here; the live push to
 * the open page is App\Events\UserNotified, broadcast right away (a queued
 * notification broadcast would wait for a worker, and "opponent found" cannot).
 */
final class LeagueNotification extends Notification
{
    public function __construct(public NotificationKind $kind, public Notice $notice) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind->value;
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string, match: int|null, action: string|null, sound: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'title' => $this->notice->title,
            'body' => $this->notice->body,
            'url' => $this->notice->url,
            'match' => $this->notice->match,
            'action' => $this->notice->action,
            'sound' => $this->notice->sound ?? $this->kind->sound(),
        ];
    }
}
