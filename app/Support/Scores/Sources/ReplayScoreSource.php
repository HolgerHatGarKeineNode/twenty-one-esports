<?php

namespace App\Support\Scores\Sources;

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Models\StackerRun;
use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Runs the league replayed itself (plan "Blockfill", P4): a Blockfill run
 * counts once the verifier reached exactly the claimed time
 * (StackerRunStatus::Verified). The player is the league user who played it,
 * so no game account is read ({@see ScoreAccount::$userId} only).
 *
 * The value is the verified time in milliseconds (Blockfill::milliseconds()),
 * the moment is the submission: the same moment that decides the run's week
 * (StackerRuns::weekOf()). Best is the fewest ticks inside [start, end) on
 * the difficulty of the week the window lies in, a tie going to the earlier
 * submission. Any other game or course has none.
 */
final class ReplayScoreSource implements ScoreSource
{
    public const KEY = 'replay';

    public function key(): string
    {
        return self::KEY;
    }

    public function bestFor(ScoreAccount $account, ScoreCourse $course, CarbonInterface $windowStart, CarbonInterface $windowEnd): ?ScoreRecord
    {
        if ($course->game->slug() !== Blockfill::SLUG || $course->id !== Blockfill::MODE) {
            return null;
        }

        $run = StackerRun::query()
            ->where('user_id', $account->userId)
            ->where('status', StackerRunStatus::Verified)
            // Only runs on the difficulty of the week the window lies in (BlockfillWeeks::difficultyAt()).
            ->where('engine', app(BlockfillWeeks::class)->difficultyAt($windowStart))
            ->whereNotNull('ticks')->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $windowStart->format('Y-m-d H:i:s.v'))
            ->where('submitted_at', '<', $windowEnd->format('Y-m-d H:i:s.v'))
            ->orderBy('ticks')->orderBy('submitted_at')->orderBy('id')
            ->first();

        return $run === null ? null : self::record($run);
    }

    /**
     * The score record of one verified run.
     */
    public static function record(StackerRun $run): ScoreRecord
    {
        return new ScoreRecord(
            Blockfill::milliseconds((int) $run->ticks),
            // Whole seconds, as score_runs stores them: the same run read again is the same row. Two equal times
            // handed in within one second therefore tie and go to the earlier joiner (ScoreRuns::standings()).
            CarbonImmutable::instance($run->submitted_at)->startOfSecond(),
            self::KEY,
            null,
            ['ticks' => (int) $run->ticks, 'engine' => $run->engine],
            (string) $run->id,
        );
    }
}
