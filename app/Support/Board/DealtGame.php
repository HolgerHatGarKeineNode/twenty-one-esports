<?php

namespace App\Support\Board;

/**
 * Rules of a game that is dealt (Blockspace): every game starts from its own position, so
 * BoardRules::start() is only the board as a picture and the core asks here instead. The
 * position keeps what was dealt, so a game's start can always be read back from any position
 * of it (a take-back of the first move, the board stepped back to the start).
 *
 * @template TPosition
 */
interface DealtGame
{
    /**
     * A fresh deal for a game of this mode.
     *
     * @return TPosition
     */
    public function deal(string $mode): mixed;

    /**
     * The start of the game this position belongs to.
     *
     * @param  TPosition  $position
     * @return TPosition
     */
    public function startOf(mixed $position): mixed;

    /**
     * Whether a move from this position shows players something dealt and still hidden. Such a move cannot be taken
     * back: a player could look at what comes next for free.
     *
     * @param  TPosition  $position
     */
    public function revealsOnMove(mixed $position): bool;
}
