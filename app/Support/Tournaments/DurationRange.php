<?php

namespace App\Support\Tournaments;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How long a tournament takes, honestly (P18, user decision 2026-09-27), in
 * the game's unit:
 *
 * - `planned`: the chooser's plan, every match in its slot (LAN-like);
 * - `typical`: online, every match also needs its overhead (finding the
 *   opponent, lobby, report);
 * - `latest`: every round runs out every deadline of the round clock.
 *
 * On site the overhead and the online deadlines do not apply: all three are
 * the plan.
 */
final readonly class DurationRange
{
    public function __construct(
        public float $planned,
        public float $typical,
        public float $latest,
    ) {}

    /** A worst case longer than this (minutes) is too long for one day: the chooser warns. */
    public const LATEST_MAX_MINUTES = 600;

    /**
     * What the chooser warns about for a start at `$start`: the typical end
     * falls after midnight in `$zone` (the league's), or the worst case runs
     * longer than {@see LATEST_MAX_MINUTES}. Only minute games.
     *
     * @return list<'after-midnight'|'too-long'>
     */
    public function warnings(CarbonInterface $start, GameProfile $profile, string $zone): array
    {
        if ($profile->isDaily()) {
            return [];
        }

        $local = CarbonImmutable::instance($start)->setTimezone($zone);
        $warnings = [];

        if ($local->addMinutes((int) round($this->typical))->toDateString() !== $local->toDateString()) {
            $warnings[] = 'after-midnight';
        }

        if ($this->latest > self::LATEST_MAX_MINUTES) {
            $warnings[] = 'too-long';
        }

        return $warnings;
    }
}
