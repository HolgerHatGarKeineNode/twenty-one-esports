<?php

namespace App\Support\Scores;

use App\Games\ScoreMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One best value a source read for a player on a course: the value in the
 * metric's unit, when it was set, a link that proves it (a replay, a
 * leaderboard page, a screenshot), the source's key, the raw answer it came
 * from (kept for an admin, never shown) and the source's own id of it.
 */
final readonly class ScoreRecord
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public int $value,
        public CarbonImmutable $achievedAt,
        public string $source,
        public ?string $proofUrl = null,
        public ?array $raw = null,
        public ?string $externalId = null,
    ) {}

    /**
     * The best of `$records` set inside [start, end) by the metric, a tie
     * going to the earlier record; a record outside the window never counts.
     *
     * @param  iterable<self>  $records
     */
    public static function best(iterable $records, ScoreMetric $metric, CarbonInterface $start, CarbonInterface $end): ?self
    {
        $best = null;

        foreach ($records as $record) {
            if ($record->achievedAt->lessThan($start) || ! $record->achievedAt->lessThan($end)) {
                continue;
            }

            $order = $best === null ? -1 : $metric->compare($record->value, $best->value);

            if ($order < 0 || ($order === 0 && $record->achievedAt->lessThan($best->achievedAt))) {
                $best = $record;
            }
        }

        return $best;
    }
}
