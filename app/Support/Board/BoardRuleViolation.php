<?php

namespace App\Support\Board;

use RuntimeException;

/**
 * A board game action the server refuses: an illegal move, a move out of
 * turn or out of sync, an action on a game that is already over. `reason` is
 * a stable code the page maps to its message; the exception message is for
 * logs. The board game twin of App\Support\Chess\ChessRuleViolation, kept
 * apart so chess stays untouched (plan "Mühle und Dame").
 */
final class BoardRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
