<?php

namespace App\Enums;

/**
 * Lifecycle of a board game other than chess (App\Models\BoardGame).
 * `aborted` ends a game before both sides made their first move: no result.
 */
enum BoardGameStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
    case Aborted = 'aborted';
}
