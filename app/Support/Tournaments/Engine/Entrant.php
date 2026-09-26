<?php

namespace App\Support\Tournaments\Engine;

/**
 * A participant as the engine sees it: an id (the tournament participant) and
 * the Elo it is seeded by (a player's or a lineup's rating in the tournament's
 * game and mode; 1000 for a new player). A mix team has no Elo
 * ({@see UNRATED}): it is seeded after everyone rated.
 *
 * `order` breaks equal ratings when set (open question 10, CEO default
 * 2026-09-26: earlier sign-up first; a mix team's place in the draw).
 */
final readonly class Entrant
{
    /** Seeded after every rated entrant (a mix team, NIP "Tournaments": they follow the lineups). */
    public const UNRATED = PHP_INT_MIN;

    public function __construct(
        public int $id,
        public int $rating = 1000,
        public ?int $order = null,
    ) {}
}
