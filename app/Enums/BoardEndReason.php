<?php

namespace App\Enums;

/**
 * Why a board game ended, where the board game core decides it: a player
 * resigned or agreed a draw, a clock ran out, the game was aborted before
 * both first moves. An end by the rules of the game (a mill too few, no move
 * left, a repetition) carries the rules' own reason code instead
 * (App\Support\Board\BoardRules::outcome()), so `board_games.end_reason` is a
 * plain string.
 */
enum BoardEndReason: string
{
    case Resignation = 'resignation';
    case Timeout = 'timeout';
    case Agreement = 'agreement';
    case Aborted = 'aborted';
}
