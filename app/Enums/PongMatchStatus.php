<?php

namespace App\Enums;

/**
 * Lifecycle of a live Proof of Pong match (App\Models\PongMatch, plan "Proof of Pong", P2): waiting for both players
 * to open the match page, active from the first serve, finished with a winner (by score, resignation or forfeit), or
 * aborted when it never started (nobody served within a minute of its start): no winner, never rated.
 */
enum PongMatchStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Finished = 'finished';
    case Aborted = 'aborted';
}
