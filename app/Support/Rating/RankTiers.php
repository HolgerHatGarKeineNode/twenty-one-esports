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

    /**
     * @return array<string, int> token => minimum rating, lowest first
     */
    public function ascending(): array
    {
        return array_reverse($this->descending, true);
    }

    /**
     * The tier's name as the rank badge shows it: `gold-2` → "Gold II",
     * anything else "Provisional". Translated.
     */
    public static function label(string $token): string
    {
        $names = [
            'bronze' => __('Bronze'),
            'silver' => __('Silver'),
            'gold' => __('Gold'),
            'platinum' => __('Platinum'),
            'diamond' => __('Diamond'),
            'champion' => __('Champion'),
            'grand-champion' => __('Grand Champion'),
        ];

        if (preg_match('/^(.+)-([123])$/', $token, $parts) !== 1 || ! isset($names[$parts[1]])) {
            return __('Provisional');
        }

        return $names[$parts[1]].' '.['I', 'II', 'III'][(int) $parts[2] - 1];
    }

    /** The tier colour of the design (`--color-rank-*` in resources/css/app.css). */
    public static function colour(string $token): string
    {
        return match (preg_replace('/-[123]$/', '', $token)) {
            'bronze' => '#E5A06B',
            'silver' => '#ADADB0',
            'gold' => '#FACC15',
            'platinum' => '#5EEAD4',
            'diamond' => '#60A5FA',
            'champion' => '#E879F9',
            'grand-champion' => '#F43F5E',
            default => '#ADADB0',
        };
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
