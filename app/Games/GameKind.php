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
 * - Score: a highscore or time attack (plan "AoE2 und Trackmania", P4): every
 *   player tries alone for a best value on a course inside a window, and the
 *   league reads that best (App\Support\Scores). No pairing, no lobby, no
 *   casual queue, no Elo: its tournaments are leaderboards and its ladder
 *   sums points per place. Every switch that serves chess, the series or the
 *   board games leaves it out.
 *
 * - Strategy: Hyperbitcoinization (plan "Hyperbitcoinization", P2), 2 to 6
 *   seats on the server's own rules core (App\Support\Hyper), a place per
 *   seat instead of a two-sided result. Registered only behind its switch;
 *   until its surfaces come (P6) every switch leaves it out.
 *
 * - Arcade: Proof of Pong (plan "Proof of Pong", P1), a real-time duel to 21
 *   points on a deterministic physics core both the server and the browser
 *   run (App\Support\Pong). Registered only behind its switch; until its
 *   surfaces come (P4) every switch leaves it out.
 *
 * A further kind is one more case here plus its answer at each switch.
 */
enum GameKind: string
{
    case Chess = 'chess';
    case Series = 'series';
    case Board = 'board';
    case Score = 'score';
    case Strategy = 'strategy';
    case Arcade = 'arcade';
}
