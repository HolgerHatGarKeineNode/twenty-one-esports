<?php

namespace App\Support\Rating;

use InvalidArgumentException;

/**
 * The ladder's `tier` rows (docs/nips/esports.md, "Rank tiers (rev. 5)"): 21
 * tokens `bronze-1` … `grand-champion-3` with their minimum rating. While an
 * entity has fewer rated results than `provisional` it shows `provisional`,
 * afterwards the highest tier whose minimum does not exceed its rating.
 */
final class RankTiers
{
    public const string Provisional = 'provisional';

    /** @var array<string, int> token => minimum rating, highest minimum first */
    private readonly array $descending;

    /**
     * @param  array<string, int>  $tiers  token => minimum rating
     */
    public function __construct(array $tiers, public readonly int $provisional = 5)
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('At least one tier is needed.');
        }

        arsort($tiers);
        $this->descending = $tiers;
    }

    public static function fromConfig(): self
    {
        /** @var array<string, int> $tiers */
        $tiers = config('season.tiers');

        return new self($tiers, (int) config('season.rating.provisional'));
    }

    public function tierFor(int $rating, int $ratedResults): string
    {
        if ($ratedResults < $this->provisional) {
            return self::Provisional;
        }

        foreach ($this->descending as $token => $minimum) {
            if ($rating >= $minimum) {
                return $token;
            }
        }

        // Below the lowest minimum: the lowest tier.
        return (string) array_key_last($this->descending);
    }
}
