<?php

namespace App\Support\Hyper;

use RuntimeException;

/**
 * A Hyperbitcoinization action the rules refuse: out of turn, in the wrong phase, an illegal target, not
 * enough fiat or sats. `reason` is a stable code the game page maps to its message; the exception message
 * is for logs. The state the action was applied to stays untouched (HyperGame::apply works on a copy).
 */
final class HyperRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
