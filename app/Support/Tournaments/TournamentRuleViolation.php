<?php

namespace App\Support\Tournaments;

use RuntimeException;

/**
 * A tournament action the league refuses before anything is signed or
 * stored. `reason` is a short machine code (tests, logs); the message is
 * translated and meant for the person who tried.
 */
final class TournamentRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
