<?php

namespace App\Support\Tournaments;

/**
 * The clock of a match in a one-day online tournament (P18, user decision
 * 2026-09-27): every deadline follows from the match's own start, so a round
 * that runs out every deadline still ends at a known time.
 *
 * - a series: a no-show can be reported after `noshowMinutes`; the result is
 *   due after the no-show wait, the longest play and `graceMinutes` (or
 *   after the tournament's own `reportHours`); the other side answers within
 *   `responseMinutes`. Then the series is closed.
 * - a chess game: the first move within `checkinMinutes`, then the clock.
 *
 * Built from `esports.tournaments.round_clock` and the tournament's own
 * deadlines, which win ({@see TournamentDeadlines::clock()}).
 */
final readonly class RoundClock
{
    public function __construct(
        public int $noshowMinutes,
        public int $graceMinutes,
        public int $responseMinutes,
        public ?int $reportHours,
        public int $checkinMinutes,
    ) {}

    /**
     * Minutes from a series' start to its report deadline.
     */
    public function reportDueMinutes(GameProfile $profile, int $bestOf): int
    {
        return $this->reportHours !== null
            ? $this->reportHours * 60
            : (int) ceil($this->noshowMinutes + $profile->longestPlay($bestOf) + $this->graceMinutes);
    }

    /**
     * The latest a match of this length is over, in the profile's unit: a
     * series whose report comes at its deadline and is never answered, a
     * chess game whose first move comes at the last second and whose clock
     * runs out.
     */
    public function hardEnd(GameProfile $profile, int $bestOf): float
    {
        if (! $profile->isSeries()) {
            return ($profile->isDaily() ? $this->checkinMinutes / 1440 : $this->checkinMinutes) + $profile->longestPlay($bestOf);
        }

        return $this->reportDueMinutes($profile, $bestOf) + $this->responseMinutes;
    }
}
