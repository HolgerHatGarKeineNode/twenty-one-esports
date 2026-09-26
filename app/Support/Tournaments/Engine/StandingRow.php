<?php

namespace App\Support\Tournaments\Engine;

/**
 * One line of a table (Standings::table). `points` are the ranking points
 * (win, draw and bye points of the tournament); `gameWins`/`gameLosses`
 * count games inside matches, `pointsFor`/`pointsAgainst` goals.
 */
final class StandingRow
{
    public int $rank = 0;

    public float $points = 0.0;

    public int $wins = 0;

    public int $ties = 0;

    public int $losses = 0;

    public int $byes = 0;

    public float $gameWins = 0.0;

    public float $gameLosses = 0.0;

    public float $pointsFor = 0.0;

    public float $pointsAgainst = 0.0;

    public float $buchholz = 0.0;

    public float $medianBuchholz = 0.0;

    /** @var list<int> */
    public array $opponents = [];

    public function __construct(
        public readonly int $entrant,
        public readonly int $seed,
    ) {}
}
