<?php

namespace App\Support\SeasonChain;

/**
 * The consensus parameters in force for one attestation: the genesis tags
 * that a Parameter Change (`2158`) may replace (`weight`, `share`, `daily`,
 * `pairlimit`, `subtree`, `moves`), and the share groups of the genesis
 * (`group`), which no change can alter.
 *
 * A share group lets several games count as one for rules 5 and 9 (NIP
 * rev. 9.5, "Share groups"): the two EA Sports FC editions share one share
 * cap and one daily limit, so a player who owns both does not mine twice as
 * much. `share` and `daily` are keyed by the share key: the group a game
 * belongs to, otherwise the game itself.
 */
final class ConsensusParameters
{
    /**
     * @param  array<string, int>  $weights  `<game>/<mode>` => factor per winning player in thousandths; 0 or absent = does not mine
     * @param  array<string, int>  $shares  share key => share cap per era in percent; absent = 100
     * @param  array<string, int>  $daily  share key => blocks per winning player and UTC day; absent = no limit
     * @param  array<string, list<string>>  $groups  share key => the games that count as one
     */
    public function __construct(
        public readonly array $weights,
        public readonly array $shares = [],
        public readonly array $daily = [],
        public readonly int $pairLimitPerDay = 1,
        public readonly int $pairLimitPerSeason = 3,
        public readonly int $subtree = 51,
        public readonly int $moves = 20,
        public readonly array $groups = [],
    ) {}

    public function weightFor(string $weightKey): int
    {
        return $this->weights[$weightKey] ?? 0;
    }

    /** The group a game belongs to, otherwise the game: what rules 5 and 9 count per. */
    public function shareKey(string $game): string
    {
        foreach ($this->groups as $key => $games) {
            if (in_array($game, $games, true)) {
                return $key;
            }
        }

        return $game;
    }

    public function shareFor(string $game): int
    {
        return $this->shares[$this->shareKey($game)] ?? 100;
    }

    public function dailyLimitFor(string $game): ?int
    {
        return $this->daily[$this->shareKey($game)] ?? null;
    }

    /**
     * A copy with the rows and values of a change replaced; a `weight`,
     * `share` or `daily` row replaces the row for its game (and mode), or
     * adds it. The groups stay as the genesis set them.
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
            $this->groups,
        );
    }
}
