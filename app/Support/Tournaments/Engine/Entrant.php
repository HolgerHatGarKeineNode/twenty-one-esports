<?php

namespace App\Support\Tournaments\Engine;

/**
 * A participant as the engine sees it: an id (the tournament participant) and
 * the Elo it is seeded by (a player's or a lineup's rating in the tournament's
 * game and mode; 1000 for a mix team or a new player).
 */
final readonly class Entrant
{
    public function __construct(
        public int $id,
        public int $rating = 1000,
    ) {}
}
