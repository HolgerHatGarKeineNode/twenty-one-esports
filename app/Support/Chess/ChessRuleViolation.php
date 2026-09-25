<?php

namespace App\Support\Chess;

use RuntimeException;

/**
 * A chess action the server refuses: an illegal move, a move out of turn,
 * an action on a game that is already over. `reason` is a stable code the
 * client maps to its message; the exception message is for logs.
 */
final class ChessRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
