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
    ) {}

    public function guaranteed(): int
    {
        return $this->structure->guaranteed ?? 0;
    }

    public function total(): float
    {
        return $this->duration->total ?? 0.0;
    }
}
