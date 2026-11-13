<?php

namespace App\Games\Contracts;

use App\Games\GameGroup;

/**
 * A game that is one of several variants players know as one game: Go on 9 x 9, 13 x 13 and 19 x 19 is "Go". Each
 * variant stays a game of its own (its ladders, tournaments, modes and pages); every page that lists games shows the
 * group once (GameRegistry::listed()) and the group's game pages offer a switch between the variants switched on.
 */
interface Grouped
{
    /** The group this game belongs to; the games that return the same slug are its members. */
    public function group(): GameGroup;

    /** What this member is called inside its group, on the switch between them: "9×9". */
    public function groupLabel(): string;
}
