<?php

namespace App\Support\Pong;

use RuntimeException;

/**
 * A Proof of Pong action the server refuses (plan "Proof of Pong", P2): an invite that cannot be sent or accepted,
 * a player already in another live game. `reason` is a stable code the page maps to its message, as
 * App\Support\Board\BoardRuleViolation for the board games.
 */
final class PongRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
