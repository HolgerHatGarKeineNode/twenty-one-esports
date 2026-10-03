<?php

namespace App\Games\Contracts;

use App\Games\GameStore;

/**
 * A game the players play outside the league's site, each in their own copy
 * (EA Sports FC, Rocket League, Age of Empires II, TrackMania Nations
 * Forever). Its tournament pages say so in big, name the platforms, and a
 * sign-up needs the player's tick that they own it (user, 2026-10-03:
 * players signed up who did not own the game).
 *
 * A game played on the site (chess, the board games, Blockfill) does not
 * implement it. Only checked platforms are listed: fewer rather than wrong.
 */
interface PlayedOnOwnCopy
{
    /**
     * @return list<GameStore> in display order
     */
    public function stores(): array;

    /**
     * Whether the game costs nothing on every listed platform.
     */
    public function isFreeToPlay(): bool;
}
