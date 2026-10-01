<?php

namespace App\Support\Scores;

use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The submission window of a score leaderboard: [start, end), the start
 * included and the end excluded, so a record set at the very end belongs to
 * the next window. A tournament's window opens at its start and lasts its
 * planned duration: the score game's default window in days, or the
 * organizer's own game length ("Change times").
 */
final readonly class ScoreWindow
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function of(Tournament $tournament): self
    {
        $start = $tournament->starts_at->toImmutable();

        return new self($start, $start->addMinutes((int) round($tournament->plannedDuration() * 1440)));
    }

    public function contains(CarbonInterface $moment): bool
    {
        return $moment->greaterThanOrEqualTo($this->start) && $moment->lessThan($this->end);
    }

    public function hasStarted(?CarbonInterface $now = null): bool
    {
        return ! ($now ?? now())->lessThan($this->start);
    }

    public function hasEnded(?CarbonInterface $now = null): bool
    {
        return ! ($now ?? now())->lessThan($this->end);
    }
}
