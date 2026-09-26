<?php

namespace App\Support\Tournaments\Engine;

/**
 * One match of a generated bracket. `key` is unique within the tournament
 * and stable for the same entrants and seed. `round` is the time step within
 * the stage: matches with the same stage and round are played side by side
 * (in double elimination the upper and lower bracket share steps, as in the
 * estimator). `bracket` names the part: main, upper, lower, grand-final,
 * reset, third-place, heat, board.
 */
final readonly class BracketMatch
{
    /**
     * @param  list<Slot>  $slots  two for a match between two sides, more for a heat or a leaderboard
     */
    public function __construct(
        public string $key,
        public int $stage,
        public ?int $group,
        public string $bracket,
        public int $round,
        public int $position,
        public array $slots,
        public bool $ifNeeded = false,
    ) {}

    public function withRound(int $round): self
    {
        return new self($this->key, $this->stage, $this->group, $this->bracket, $round, $this->position, $this->slots, $this->ifNeeded);
    }
}
