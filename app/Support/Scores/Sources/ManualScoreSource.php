<?php

namespace App\Support\Scores\Sources;

use App\Models\ScoreRun;
use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Values players submitted themselves with a proof URL (plan "AoE2 und
 * Trackmania", P4; App\Support\Scores\ManualSubmissions): only what an admin
 * verified counts, a pending or rejected submission never does.
 */
final class ManualScoreSource implements ScoreSource
{
    public function key(): string
    {
        return ScoreRun::MANUAL;
    }

    public function bestFor(ScoreAccount $account, ScoreCourse $course, CarbonInterface $windowStart, CarbonInterface $windowEnd): ?ScoreRecord
    {
        $runs = ScoreRun::query()
            ->where(['source' => ScoreRun::MANUAL, 'user_id' => $account->userId, 'game' => $course->game->slug(), 'mode' => $course->mode->slug, 'course' => $course->id])
            ->whereNotNull('verified_at')->whereNull('rejected_at')->whereNotNull('value')
            ->where('achieved_at', '>=', $windowStart)->where('achieved_at', '<', $windowEnd)
            ->get();

        return ScoreRecord::best($runs->map(fn (ScoreRun $run): ScoreRecord => new ScoreRecord(
            (int) $run->value, CarbonImmutable::instance($run->achieved_at), ScoreRun::MANUAL, $run->proof_url, null, (string) $run->id,
        )), $course->metric(), $windowStart, $windowEnd);
    }
}
