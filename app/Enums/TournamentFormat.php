<?php

namespace App\Enums;

/**
 * The tournament systems of the format chooser (P8), the list Challonge offers
 * (TOURNAMENT-FORMATS.md, section 1). Leaderboard is Challonge's Race product,
 * not one of its tournament types; it is kept because the user asked for it.
 * The case order is the row order of the chooser.
 */
enum TournamentFormat: string
{
    case Swiss = 'swiss';
    case RoundRobin = 'round-robin';
    case TwoStage = 'two-stage';
    case SingleElimination = 'single-elimination';
    case DoubleElimination = 'double-elimination';
    case FreeForAll = 'free-for-all';
    case Leaderboard = 'leaderboard';

    /**
     * Ends with a final match (recommendation rule 4; for Rocket League the
     * first rule, a decision of the user from 2026-09-26).
     */
    public function hasFinal(): bool
    {
        return match ($this) {
            self::SingleElimination, self::DoubleElimination, self::TwoStage, self::FreeForAll => true,
            self::RoundRobin, self::Swiss, self::Leaderboard => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Swiss => __('Swiss'),
            self::RoundRobin => __('Round Robin'),
            self::TwoStage => __('Two Stage'),
            self::SingleElimination => __('Single Elimination'),
            self::DoubleElimination => __('Double Elimination'),
            self::FreeForAll => __('Free for All'),
            self::Leaderboard => __('Leaderboard'),
        };
    }
}
