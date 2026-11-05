<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;

/**
 * One row of the format chooser: a format that is disabled for this game or
 * size (with the reason), or its structure, duration and fit.
 *
 * `fit` is `fits`, `over` (up to 10 % over the time) or `long`. `atOnce` is
 * the number of games one daily-chess player runs at the same time, `heavy`
 * when that is more than the limit.
 *
 * `perMatch` is the games one match plays where a match is games, not a
 * series: Blockli plays each pairing twice (or once in a table, by choice),
 * so its counts read in games, not matches (DerCaddy, 2026-11-04: "mindestens
 * 9 Spiele" for 6 games against every opponent, 18 in all). `knockoutPerMatch`
 * is the games of a knockout match, which differs from `perMatch` only in the
 * knockout after round-robin groups of one game per pairing.
 */
final readonly class EstimateRow
{
    public function __construct(
        public TournamentFormat $format,
        public bool $enabled,
        public string $reason = '',
        public ?Structure $structure = null,
        public ?Duration $duration = null,
        public string $fit = 'long',
        public int $atOnce = 0,
        public bool $heavy = false,
        public int $perMatch = 1,
        public int $knockoutPerMatch = 1,
    ) {}

    /** The matches everyone plays at least. */
    public function guaranteed(): int
    {
        return $this->structure->guaranteed ?? 0;
    }

    /** The games everyone plays at least ({@see $perMatch}): what the chooser shows and recommends by. */
    public function guaranteedGames(): int
    {
        return $this->guaranteed() * $this->perMatch;
    }

    /** `$matches` matches in games ({@see $perMatch}). */
    public function games(int $matches): int
    {
        return $matches * $this->perMatch;
    }

    /**
     * The games one player plays at most. Two-stage after round-robin groups counts the groups at `perMatch` and the
     * knockout at `knockoutPerMatch` (a Blockli knockout plays two games a match whatever the groups play).
     */
    public function maxGames(): int
    {
        $max = $this->structure->max ?? 0;

        if ($this->structure === null || $this->structure->groups === [] || $this->knockoutPerMatch === $this->perMatch) {
            return $max * $this->perMatch;
        }

        $group = max($this->structure->groups) - 1;

        return $group * $this->perMatch + ($max - $group) * $this->knockoutPerMatch;
    }

    public function total(): float
    {
        return $this->duration->total ?? 0.0;
    }
}
