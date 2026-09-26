<?php

namespace App\Support\Tournaments;

/**
 * The shape of one format for N participants, as the estimator counts it:
 * the rounds in play order with their match count, the total matches, the
 * games per participant (fewest to most) and the games the weakest one is
 * guaranteed (TOURNAMENT-FORMATS.md, section 3, table).
 *
 * `rounds` are `{m: matches, final: plays the final best-of, ifNeeded: the
 * grand-final reset}`; for double elimination they are time steps, the
 * upper and lower bracket playing side by side.
 */
final readonly class Structure
{
    /**
     * @param  list<array{m: int, final: bool, ifNeeded: bool}>  $rounds
     * @param  list<int>  $groups  group sizes (Two Stage)
     */
    public function __construct(
        public array $rounds,
        public int $matches,
        public int $min,
        public int $max,
        public int $guaranteed,
        public int $byes = 0,
        public int $byesPerRound = 0,
        public array $groups = [],
        public int $advance = 0,
        public int $finalists = 0,
        public int $groupDepth = 0,
    ) {}

    /**
     * @param  list<array{m: int, final: bool, ifNeeded: bool}>  $rounds
     */
    public function withRounds(array $rounds): self
    {
        return new self($rounds, $this->matches, $this->min, $this->max, $this->guaranteed, $this->byes,
            $this->byesPerRound, $this->groups, $this->advance, $this->finalists, $this->groupDepth);
    }
}
