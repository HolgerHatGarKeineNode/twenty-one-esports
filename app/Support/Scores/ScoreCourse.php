<?php

namespace App\Support\Scores;

use App\Games\GameMode;
use App\Games\ScoreGame;
use App\Games\ScoreMetric;

/**
 * A course of a score game in one mode (a track, a level, a fixed mode),
 * with the metric its values are measured in.
 */
final readonly class ScoreCourse
{
    public function __construct(public ScoreGame $game, public GameMode $mode, public string $id) {}

    public function metric(): ScoreMetric
    {
        return $this->game->metric($this->mode);
    }
}
