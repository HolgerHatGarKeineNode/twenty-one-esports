<?php

namespace App\Support\Scores;

use Carbon\CarbonImmutable;

/**
 * One finish our own dedicated server saw (plan "AoE2 und Trackmania", P4):
 * the server's own id of the event (idempotency), the mode and course, the
 * player's game account id (private: mapped to a league player, never
 * shown), the value and when it was set, and what the server sent.
 */
final readonly class FinishEvent
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public string $id,
        public string $mode,
        public string $course,
        public string $accountId,
        public int $value,
        public CarbonImmutable $achievedAt,
        public ?array $raw = null,
    ) {}
}
