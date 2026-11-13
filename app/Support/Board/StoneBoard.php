<?php

namespace App\Support\Board;

/**
 * Rules of a board whose position is one stone or none on each point (Go): its stones as one short text, so a push
 * and a game's record carry a board of 361 points in 361 characters and not as one object per stone (a Reverb message
 * holds 10 kB; BoardGameService::snapshot()).
 */
interface StoneBoard
{
    /**
     * The stones of a stored position, one character per point of view()['points'] in their order: `.` for an empty
     * point, `x` for a stone of the side that moves first, `o` for one of the other side. It reads the stored text
     * alone and checks nothing, so a whole record is read without judging every position again.
     */
    public function stones(string $serialized): string;
}
