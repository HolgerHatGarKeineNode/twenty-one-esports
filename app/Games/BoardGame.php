<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * A board game other than chess, played move by move on our server (plan
 * "Mühle und Dame": nine men's morris, checkers). The board game core
 * (rules, moves, clock) comes in P2; here it only names its kind, so every
 * switch in the league tells it from chess and from a series.
 *
 * A board game is registered through `config('esports.board_games')`, never
 * through `esports.games`, so the switch there keeps it off the site.
 */
abstract class BoardGame implements Game
{
    /**
     * The slugs kept for the planned board games: nine men's morris is
     * `nine-mens-morris`, never `mill` (resources/js/millAuth.js is the
     * nostr-mill login; a game named "mill" would mix up search and names).
     */
    public const RESERVED_SLUGS = ['nine-mens-morris', 'checkers'];

    final public function kind(): GameKind
    {
        return GameKind::Board;
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }
}
