<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Deterministic UTC anchors for a test that must not depend on the real
 * wall-clock time the suite happens to run at (tests/Feature/SeasonChain/
 * RatedTrustGateTest.php and ChainFlowTest.php: both travel the clock
 * forward across several rated series, and whether that drift crosses a
 * UTC day boundary decided the outcome of a UTC-day-scoped rule depending
 * on the real time of day the suite ran).
 */
final class TimeAnchors
{
    /**
     * The next UTC instant at $hour:$minute strictly after "now" (never
     * today's already-past occurrence) — always after a season's
     * genesis_at, which openSeason() pins to "now minus one hour" before
     * any of this runs.
     */
    public static function nextUtcTime(int $hour, int $minute): CarbonImmutable
    {
        $today = CarbonImmutable::now('UTC')->setTime($hour, $minute, 0);

        return $today->isFuture() ? $today : $today->addDay();
    }

    /**
     * The next Europe/Berlin DST transition (spring forward or fall back),
     * as its UTC instant — a defensive case: this UTC-day logic must not
     * care about Berlin's clock at all, DST or not.
     */
    public static function nextBerlinDstTransition(): CarbonImmutable
    {
        $from = CarbonImmutable::now('UTC');
        $transitions = (new DateTimeZone('Europe/Berlin'))->getTransitions($from->getTimestamp(), $from->addYears(2)->getTimestamp());

        foreach ($transitions as $transition) {
            if ($transition['ts'] > CarbonImmutable::now('UTC')->getTimestamp()) {
                return CarbonImmutable::createFromTimestamp($transition['ts'], 'UTC');
            }
        }

        throw new RuntimeException('No upcoming Europe/Berlin DST transition found in the next two years.');
    }
}
