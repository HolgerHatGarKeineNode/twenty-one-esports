<?php

namespace App\Enums;

/**
 * How a live Proof of Pong match ended (plan "Proof of Pong", P2): one side reached the winning score (score), a
 * player gave up (resign), a player stayed away longer than the grace (forfeit), or it never started (abort).
 */
enum PongEndReason: string
{
    case Score = 'score';
    case Resign = 'resign';
    case Forfeit = 'forfeit';
    case Abort = 'abort';
}
