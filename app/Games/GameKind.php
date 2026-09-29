<?php

namespace App\Games;

/**
 * How a game is played: the question every "chess or the rest" switch asks
 * (plan "Mühle und Dame", P1). A switch names the kinds it serves and leaves
 * the others out explicitly; nothing is "not chess, so a series".
 *
 * - Chess: played live on our server, move by move (App\Support\Chess).
 * - Series: a best-of series both sides report (Rocket League, EA Sports FC).
 * - Board: a further board game on our server's own board game core (nine
 *   men's morris, checkers), next to chess and not built on it. Until its
 *   phase opens a feature (P5 play and ladders, P6 mining) every switch
 *   leaves it out.
 *
 * A further kind (the time attack of the AoE2/Trackmania plan) is one more
 * case here plus its answer at each switch.
 */
enum GameKind: string
{
    case Chess = 'chess';
    case Series = 'series';
    case Board = 'board';
}
