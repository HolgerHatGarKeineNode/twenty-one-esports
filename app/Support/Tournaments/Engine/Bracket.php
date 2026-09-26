<?php

namespace App\Support\Tournaments\Engine;

use App\Enums\TournamentFormat;

/**
 * A generated tournament: the entrants in seed order, the groups (Two Stage),
 * and every match that is known before the first result. Swiss holds round 1
 * only; its later rounds are paired from the results (Swiss::pairRound).
 */
final readonly class Bracket
{
    /**
     * @param  list<int>  $seeds  entrant ids, seed 1 first
     * @param  array<int, list<int>>  $groups  group number => entrant ids, best seed first
     * @param  list<BracketMatch>  $matches
     */
    public function __construct(
        public TournamentFormat $format,
        public array $seeds,
        public array $groups,
        public array $matches,
    ) {}

    public function match(string $key): ?BracketMatch
    {
        foreach ($this->matches as $match) {
            if ($match->key === $key) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Real matches, without Swiss byes.
     *
     * @return list<BracketMatch>
     */
    public function played(): array
    {
        return array_values(array_filter($this->matches, fn (BracketMatch $match): bool => $match->bracket !== 'bye'));
    }

    /**
     * Number of time steps per stage.
     *
     * @return array<int, int>
     */
    public function rounds(): array
    {
        $rounds = [];

        foreach ($this->matches as $match) {
            $rounds[$match->stage] = max($rounds[$match->stage] ?? 0, $match->round);
        }

        ksort($rounds);

        return $rounds;
    }
}
