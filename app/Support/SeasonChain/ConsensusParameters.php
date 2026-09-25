<?php

namespace App\Support\SeasonChain;

/**
 * The consensus parameters in force for one attestation: the genesis tags
 * that a Parameter Change (`2158`) may replace (`weight`, `share`, `daily`,
 * `pairlimit`, `subtree`, `moves`).
 */
final class ConsensusParameters
{
    /**
     * @param  array<string, int>  $weights  `<game>/<mode>` => factor per winning player in thousandths; 0 or absent = does not mine
     * @param  array<string, int>  $shares  game => share cap per era in percent; absent = 100
     * @param  array<string, int>  $daily  game => blocks per winning player and UTC day; absent = no limit
     */
    public function __construct(
        public readonly array $weights,
        public readonly array $shares = [],
        public readonly array $daily = [],
        public readonly int $pairLimitPerDay = 1,
        public readonly int $pairLimitPerSeason = 3,
        public readonly int $subtree = 51,
        public readonly int $moves = 20,
    ) {}

    public function weightFor(string $weightKey): int
    {
        return $this->weights[$weightKey] ?? 0;
    }

    public function shareFor(string $game): int
    {
        return $this->shares[$game] ?? 100;
    }

    public function dailyLimitFor(string $game): ?int
    {
        return $this->daily[$game] ?? null;
    }

    /**
     * A copy with the rows and values of a change replaced; a `weight`,
     * `share` or `daily` row replaces the row for its game (and mode).
     */
    public function with(ParameterChange $change): self
    {
        return new self(
            array_replace($this->weights, $change->weights),
            array_replace($this->shares, $change->shares),
            array_replace($this->daily, $change->daily),
            $change->pairLimit[0] ?? $this->pairLimitPerDay,
            $change->pairLimit[1] ?? $this->pairLimitPerSeason,
            $change->subtree ?? $this->subtree,
            $change->moves ?? $this->moves,
        );
    }
}
