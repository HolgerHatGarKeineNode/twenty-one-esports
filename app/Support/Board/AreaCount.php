<?php

namespace App\Support\Board;

/**
 * Rules of a game counted by area when it ends (Go, DerCaddy 2026-10-27: play it out, then count): for every position,
 * what each side would have if the game ended now. The board game core puts it into every snapshot, and the board
 * shows it beside the game, as it shows the race standing of Blockli (RaceStanding): so a player sees what passing
 * would mean before both pass.
 *
 * `stones` are a side's stones on the board, `area` the empty points that touch only its stones, `score` both
 * together plus `komi` for the side that moves second. `margin` is the first side's score minus the second's (above
 * zero `w` leads), `lead` the side ahead or null when both are level. `owners` names the empty points each side's
 * area holds, for a board that marks them.
 *
 * @template TPosition
 */
interface AreaCount
{
    /**
     * @param  TPosition  $position
     * @return array{w: array{stones: int, area: int, score: float}, b: array{stones: int, area: int, score: float}, komi: float, margin: float, lead: 'w'|'b'|null, owners: array{w: list<string>, b: list<string>}}
     */
    public function areaCount(mixed $position): array;
}
