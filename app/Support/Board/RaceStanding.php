<?php

namespace App\Support\Board;

/**
 * Rules of a race (Blockli) that can say, for every position, who would be
 * ahead: each side's steps to its goal and the blocks it holds, a block
 * worth `rate` steps. The board game core puts it into every snapshot and
 * the board shows it beside the game (DerCaddy, 2026-10-09); a tournament
 * could decide a drawn game by it, nothing does yet.
 *
 * `score` is steps minus `rate` per block, the lower leads; `margin` is
 * Black's score minus White's (above zero White leads), `lead` the side
 * ahead or null when both are level.
 *
 * @template TPosition
 */
interface RaceStanding
{
    /**
     * @param  TPosition  $position
     * @return array{w: array{steps: int, blocks: int, score: float}, b: array{steps: int, blocks: int, score: float}, margin: float, lead: 'w'|'b'|null, rate: float}
     */
    public function standing(mixed $position): array;
}
