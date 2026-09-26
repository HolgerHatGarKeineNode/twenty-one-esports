<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;

/**
 * Result of the estimator for one input: every row, the recommended format,
 * whether nothing fits the time, and the Swiss rounds the rows were built with.
 */
final readonly class Evaluation
{
    /**
     * @param  list<EstimateRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?TournamentFormat $recommended,
        public bool $nothingFits,
        public int $swissRounds,
        public FormatOptions $options,
    ) {}

    public function row(TournamentFormat $format): EstimateRow
    {
        foreach ($this->rows as $row) {
            if ($row->format === $format) {
                return $row;
            }
        }

        return new EstimateRow($format, false);
    }
}
