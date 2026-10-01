<?php

namespace Tests\Support;

use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Scores\Sources\HttpScorePoller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * A made-up HTTP source for the poller base (plan "AoE2 und Trackmania",
 * P4): `GET https://scores.example.test/records/{account}?course=…` answers
 * `{"records": [{"time": ms, "at": unix, "replay": url}]}`. Test-only; no
 * real game is read anywhere.
 */
final class FixtureScorePoller extends HttpScorePoller
{
    public function key(): string
    {
        return 'fixture-api';
    }

    protected function url(ScoreAccount $account, ScoreCourse $course): string
    {
        return 'https://scores.example.test/records/'.rawurlencode((string) $account->accountId);
    }

    protected function query(ScoreAccount $account, ScoreCourse $course): array
    {
        return ['course' => $course->id];
    }

    protected function records(Response $response, ScoreAccount $account, ScoreCourse $course): array
    {
        return array_map(fn (array $row): ScoreRecord => new ScoreRecord((int) $row['time'], CarbonImmutable::createFromTimestamp((int) $row['at']), $this->key(), $row['replay'] ?? null, $row),
            (array) $response->json('records', []));
    }
}
