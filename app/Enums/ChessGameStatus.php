<?php

namespace App\Enums;

/**
 * Lifecycle of a live chess game. `aborted` ends a game before both sides
 * made their first move: no result, nothing recorded for rating.
 */
enum ChessGameStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
    case Aborted = 'aborted';
}
