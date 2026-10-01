<?php

namespace App\Support\Scores\Sources;

use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Scores\ScoreSourceUnavailable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The polite base of every source that reads a game's HTTP API (plan "AoE2
 * und Trackmania", P4). No concrete game here: a game's poller names its URL,
 * its query and headers, and turns an answer into records; this base asks.
 *
 * - User agent: `esports.score_games.poller.user_agent` (name + contact), on
 *   every request.
 * - Rate limit: at most one request per `min_interval_ms` per source key; the
 *   caller waits (Sleep, fakeable) instead of sending faster. The spacing is
 *   kept in the cache, which holds for the one scheduled job that polls.
 * - Backoff: a 429, a 5xx or no connection is retried `retries` times, the
 *   wait doubling from `backoff_ms`; a `Retry-After` in seconds wins, up to
 *   `max_retry_after_seconds` (above it the source counts as down).
 * - No redirect is followed, and an answer over `max_body_bytes` is refused.
 * - Failing: any other answer, or the last retry failing, throws
 *   ScoreSourceUnavailable. "Could not ask" is never "no record".
 */
abstract class HttpScorePoller implements ScoreSource
{
    /**
     * The URL that lists the player's records on the course.
     */
    abstract protected function url(ScoreAccount $account, ScoreCourse $course): string;

    /**
     * The records in a successful answer (the source may list more than the
     * best: the base picks the best inside the window).
     *
     * @return list<ScoreRecord>
     */
    abstract protected function records(Response $response, ScoreAccount $account, ScoreCourse $course): array;

    /**
     * @return array<string, scalar>
     */
    protected function query(ScoreAccount $account, ScoreCourse $course): array
    {
        return [];
    }

    /**
     * Headers besides the user agent (an API key, a session id).
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    public function bestFor(ScoreAccount $account, ScoreCourse $course, CarbonInterface $windowStart, CarbonInterface $windowEnd): ?ScoreRecord
    {
        return ScoreRecord::best($this->records($this->fetch($account, $course), $account, $course), $course->metric(), $windowStart, $windowEnd);
    }

    /**
     * @throws ScoreSourceUnavailable
     */
    protected function fetch(ScoreAccount $account, ScoreCourse $course): Response
    {
        $retries = max(0, (int) config('esports.score_games.poller.retries', 3));
        $backoff = max(0, (int) config('esports.score_games.poller.backoff_ms', 2000));

        for ($attempt = 0; ; $attempt++) {
            $this->waitForTurn();

            $reason = 'no connection';

            try {
                $response = Http::withUserAgent((string) config('esports.score_games.poller.user_agent'))
                    ->withHeaders($this->headers())
                    ->timeout(max(1, (int) config('esports.score_games.poller.timeout_seconds', 10)))
                    // A redirect is no answer (security gate F5): it could lead anywhere, the league follows none.
                    ->withoutRedirecting()
                    ->acceptJson()
                    ->get($this->url($account, $course), $this->query($account, $course));
            } catch (ConnectionException $e) {
                $response = null;
                $reason = $e->getMessage();
            }

            if ($response !== null && $response->successful()) {
                $limit = max(1, (int) config('esports.score_games.poller.max_body_bytes', 1_048_576));
                $length = $response->header('Content-Length');

                if ((is_numeric($length) && (int) $length > $limit) || strlen($response->body()) > $limit) {
                    throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: an answer larger than {$limit} bytes.");
                }

                return $response;
            }

            $retryable = $response === null || $response->status() === 429 || $response->serverError();
            $reason = $response === null ? $reason : 'HTTP '.$response->status();

            if (! $retryable || $attempt >= $retries) {
                throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: {$reason}.");
            }

            $retryAfter = $response?->header('Retry-After');
            $cap = max(0, (int) config('esports.score_games.poller.max_retry_after_seconds', 60));

            // A source that asks for a longer wait than the cap is down for now (security gate F5): never sleep for it.
            if (is_numeric($retryAfter) && (int) $retryAfter > $cap) {
                throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: Retry-After {$retryAfter} s is over {$cap} s.");
            }

            $wait = is_numeric($retryAfter) ? max(0, (int) $retryAfter) * 1000 : $backoff * (2 ** $attempt);
            Sleep::for($wait)->milliseconds();
        }
    }

    private function waitForTurn(): void
    {
        $interval = max(0, (int) config('esports.score_games.poller.min_interval_ms', 1000));
        $key = "score-poller:{$this->key()}:last";
        $now = (int) floor(microtime(true) * 1000);
        $last = Cache::get($key);
        $wait = is_int($last) ? $last + $interval - $now : 0;

        if ($wait > 0) {
            Sleep::for($wait)->milliseconds();
            $now += $wait;
        }

        Cache::put($key, $now, now()->addMinutes(10));
    }
}
