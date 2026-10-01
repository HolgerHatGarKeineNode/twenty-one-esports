<?php

namespace App\Support\Scores\Sources;

use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreRecord;
use App\Support\Scores\ScoreSourceUnavailable;
use Carbon\CarbonInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

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
 * - No redirect is followed; the answer is uncompressed and refused once it
 *   passes `max_body_bytes`: cURL stops the transfer there itself
 *   (CURLOPT_MAXFILESIZE_LARGE, which since cURL 8.4 also ends an answer of
 *   unknown length once it grows past the cap), and the stored answer is read
 *   only up to the cap again.
 * - Time: the whole transfer, headers included, takes at most
 *   `timeout_seconds` (cURL's total timeout, round-4 F3: a header that drips
 *   one byte at a time is cut off too); reading the stored answer has its
 *   own deadline, `read_deadline_seconds` (round-3 S4).
 * - Failing: any other answer, or the last retry failing, throws
 *   ScoreSourceUnavailable. "Could not ask" is never "no record". A 4xx
 *   other than 429 refuses this player only; the rest mean the source is
 *   down (round-3 S3). A player with no confirmed account id is not asked.
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
        // Round-3 S3: without a confirmed id there is nobody to ask for (never a request with an empty id).
        if ($account->accountId === null || $account->accountId === '') {
            return null;
        }

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
            $limit = max(1, (int) config('esports.score_games.poller.max_body_bytes', 1_048_576));

            try {
                $response = Http::withUserAgent((string) config('esports.score_games.poller.user_agent'))
                    ->withHeaders($this->headers())
                    // Round-4 F3: not streamed, so this is cURL's total timeout for the whole transfer, headers included.
                    ->timeout(max(1, (int) config('esports.score_games.poller.timeout_seconds', 10)))
                    ->connectTimeout(max(1, (int) config('esports.score_games.poller.timeout_seconds', 10)))
                    // A redirect is no answer (security gate F5): it could lead anywhere, the league follows none.
                    ->withoutRedirecting()
                    // Re-audit N3: the answer is kept only up to the cap; no compression, so a small answer on the wire
                    // can never unpack into a big one.
                    ->withHeaders(['Accept-Encoding' => 'identity'])
                    ->withOptions(['decode_content' => false, 'curl' => [CURLOPT_MAXFILESIZE_LARGE => $limit]])
                    ->acceptJson()
                    ->get($this->url($account, $course), $this->query($account, $course));
            } catch (ConnectionException|RequestException $e) {
                // cURL error 63: the answer grew past the cap, and cURL ended the transfer.
                if (str_contains($e->getMessage(), 'cURL error 63')) {
                    throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: an answer larger than {$limit} bytes.", true, $e);
                }

                $response = null;
                $reason = $e->getMessage();
            }

            if ($response !== null && $response->successful()) {
                return $this->bounded($response);
            }

            $retryable = $response === null || $response->status() === 429 || $response->serverError();
            $reason = $response === null ? $reason : 'HTTP '.$response->status();

            if (! $retryable || $attempt >= $retries) {
                // A 4xx (not 429) is about this player (an unknown or removed account): the source itself is up.
                throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: {$reason}.", $retryable);
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

    /**
     * The answer read up to `max_body_bytes`, never further: a larger or a
     * compressed one is refused before more than the cap and one chunk were
     * read (re-audit N3).
     *
     * @throws ScoreSourceUnavailable
     */
    private function bounded(Response $response): Response
    {
        $limit = max(1, (int) config('esports.score_games.poller.max_body_bytes', 1_048_576));
        $encoding = strtolower(trim($response->header('Content-Encoding')));
        $length = $response->header('Content-Length');
        $refuse = fn (string $why): ScoreSourceUnavailable => new ScoreSourceUnavailable("Score source [{$this->key()}] refused: {$why}.");

        if ($encoding !== '' && $encoding !== 'identity') {
            throw $refuse("a {$encoding} answer, asked for none");
        }

        if (is_numeric($length) && (int) $length > $limit) {
            throw $refuse("an answer larger than {$limit} bytes");
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        // Round-3 S4: with a streamed answer the timeout holds per read only, so the whole read gets a deadline.
        $seconds = max(1, (int) config('esports.score_games.poller.read_deadline_seconds', 10));
        $deadline = microtime(true) + $seconds;

        try {
            while (! $stream->eof()) {
                if (microtime(true) > $deadline) {
                    $stream->close();

                    throw $refuse("an answer not read within {$seconds} s");
                }

                $body .= $stream->read(8192);

                if (strlen($body) > $limit) {
                    $stream->close();

                    throw $refuse("an answer larger than {$limit} bytes");
                }
            }
        } catch (RuntimeException $e) {
            if ($e instanceof ScoreSourceUnavailable) {
                throw $e;
            }

            throw new ScoreSourceUnavailable("Score source [{$this->key()}] refused: an answer it could not read ({$e->getMessage()}).", true, $e);
        }

        return new Response(new PsrResponse($response->status(), $response->headers(), $body));
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
