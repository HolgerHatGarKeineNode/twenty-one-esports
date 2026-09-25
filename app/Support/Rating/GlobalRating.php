<?php

namespace App\Support\Rating;

/**
 * Global Rating of one player for the current season
 * (docs/nips/esports.md, "Global Rating"): the weighted mean of the player's
 * percentiles over the season's ladders, mapped through the inverse normal
 * distribution: round(1000 + 200 * invnorm(pbar)). No value below the minimum
 * weight.
 */
final class GlobalRating
{
    public function __construct(public readonly int $minimumWeight = 5) {}

    public static function fromConfig(): self
    {
        return new self((int) config('season.global_rating_min_weight'));
    }

    /**
     * p = (entities rated lower + 0.5 * entities rated equal, itself included) / N.
     *
     * @param  list<int>  $ladderRatings  the ratings of every standing row of the ladder, the entity's included
     */
    public static function percentile(array $ladderRatings, int $rating): float
    {
        $lower = count(array_filter($ladderRatings, fn (int $other): bool => $other < $rating));
        $equal = count(array_filter($ladderRatings, fn (int $other): bool => $other === $rating));

        return ($lower + 0.5 * $equal) / count($ladderRatings);
    }

    /**
     * @param  list<array{weight: int, rating: int, ladder: list<int>}>  $entries  one per ladder and entity the player played for
     */
    public function compute(array $entries): ?int
    {
        $totalWeight = array_sum(array_column($entries, 'weight'));

        if ($totalWeight < $this->minimumWeight) {
            return null;
        }

        $weighted = 0.0;

        foreach ($entries as $entry) {
            $weighted += $entry['weight'] * self::percentile($entry['ladder'], $entry['rating']);
        }

        return NipMath::round(1000 + 200 * NipMath::inverseNormal($weighted / $totalWeight));
    }
}
