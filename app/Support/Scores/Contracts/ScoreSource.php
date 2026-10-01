<?php

namespace App\Support\Scores\Contracts;

use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Scores\ScoreSourceUnavailable;
use Carbon\CarbonInterface;

/**
 * Where the league reads a player's best on a course (plan "AoE2 und
 * Trackmania", P4): a manual submission an admin approved, a game's API, our
 * own dedicated server. The one question every source answers is the same,
 * so a game swaps sources without touching the leaderboard.
 */
interface ScoreSource
{
    /**
     * The source's key, stored with every run it gave (`score_runs.source`).
     */
    public function key(): string;

    /**
     * The player's best value on the course with `achieved_at` inside
     * [windowStart, windowEnd), a tie going to the earlier record; null when
     * the source knows none inside the window.
     *
     * @throws ScoreSourceUnavailable when the source could not be asked (down,
     *                                rate limited, refused): that is no answer, never "no record"
     */
    public function bestFor(ScoreAccount $account, ScoreCourse $course, CarbonInterface $windowStart, CarbonInterface $windowEnd): ?ScoreRecord;
}
