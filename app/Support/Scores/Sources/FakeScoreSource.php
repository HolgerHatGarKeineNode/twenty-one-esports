<?php

namespace App\Support\Scores\Sources;

use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Scores\ScoreSourceUnavailable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A score source that answers from records put into it, for tests and the
 * demo game (plan "AoE2 und Trackmania", P4), like the framework's fakes.
 * Empty by default: bound as a singleton, a test fills it with record() and
 * can make it fail with down(). Records are kept per league player and
 * course, every one of them, so a later record never hides the window's best.
 */
final class FakeScoreSource implements ScoreSource
{
    public const KEY = 'fake';

    /** @var array<string, list<ScoreRecord>> */
    private array $records = [];

    private bool $down = false;

    public function key(): string
    {
        return self::KEY;
    }

    public function record(int $userId, string $course, int $value, CarbonInterface $achievedAt, ?string $proofUrl = null): self
    {
        $this->records["{$userId}|{$course}"][] = new ScoreRecord($value, CarbonImmutable::instance($achievedAt), self::KEY, $proofUrl, ['fake' => true]);

        return $this;
    }

    public function down(bool $down = true): self
    {
        $this->down = $down;

        return $this;
    }

    public function bestFor(ScoreAccount $account, ScoreCourse $course, CarbonInterface $windowStart, CarbonInterface $windowEnd): ?ScoreRecord
    {
        if ($this->down) {
            throw new ScoreSourceUnavailable('The fake score source is down.');
        }

        return ScoreRecord::best($this->records["{$account->userId}|{$course->id}"] ?? [], $course->metric(), $windowStart, $windowEnd);
    }
}
