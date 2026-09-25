<?php

namespace App\Support\Series;

use RuntimeException;

/**
 * A series action the league refuses before anything is signed or stored.
 * `reason` is a short machine code (tests, logs); the message is translated
 * and meant for the player.
 */
final class SeriesRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
