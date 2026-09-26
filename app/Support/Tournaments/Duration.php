<?php

namespace App\Support\Tournaments;

/**
 * Planned duration of one format in the game's unit (`tfDuration`): one block
 * per round, the breaks between them, and the total with and without the
 * grand-final reset that is only played if needed.
 */
final readonly class Duration
{
    /**
     * @param  list<array{t: float, m: int, waves: int, final: bool, ifNeeded: bool, merged: int}>  $blocks
     */
    public function __construct(
        public array $blocks,
        public float $play,
        public float $breaks,
        public float $total,
        public float $withoutIfNeeded,
    ) {}
}
