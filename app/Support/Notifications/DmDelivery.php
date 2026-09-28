<?php

namespace App\Support\Notifications;

use App\Models\NostrEvent;

/**
 * One notification DM as it went out ({@see NotificationDm::deliver()}):
 * the stored event, its format and each relay's answer.
 */
final readonly class DmDelivery
{
    /**
     * @param  'nip17'|'nip04'  $format
     * @param  array<string, array{accepted: bool, message: string}>  $results
     */
    public function __construct(
        public NostrEvent $event,
        public string $format,
        public array $results,
    ) {}

    public function accepted(): int
    {
        return count(array_filter($this->results, fn (array $result): bool => $result['accepted']));
    }
}
