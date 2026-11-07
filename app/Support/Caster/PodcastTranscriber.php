<?php

namespace App\Support\Caster;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The workstation side of the podcast transcript (`php artisan
 * caster:podcast-transcript`, user 2026-10-12): reads the newest episode of
 * the feed (`tv.commentary.chat.podcast_feed`, PodcastFeed::newestAudio()),
 * asks the server which episode it has (skips when it is this one),
 * downloads the audio into a private temp directory, cuts it with ffmpeg
 * (under `nice`) into `chunk_seconds` mono 16 kHz FLAC pieces, deletes the
 * download, sends each piece to the local VoiceStudio Whisper
 * (`transcribe_url`, OpenAI-compatible) and deletes it, and uploads the text
 * (at most `max_bytes`) to the server. No audio stays on the disk: the temp
 * directory goes in every case.
 *
 * Never during the quiet hours (the GPU idles at night); the caller retries
 * the next day after a failure.
 */
class PodcastTranscriber
{
    public const QUIET = 'quiet';

    public const DONE = 'done';

    public const TRANSCRIBED = 'transcribed';

    public const NO_EPISODE = 'no_episode';

    public const OFFLINE = 'offline';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    /** Room for the JSON around the transcript in the upload. */
    private const ENVELOPE_BYTES = 4_096;

    /** Seconds the last transcription took (download, cut, Whisper), 0 when none ran. */
    public float $seconds = 0.0;

    /** Bytes of the last uploaded transcript, 0 when none. */
    public int $bytes = 0;

    /** The episode the last run looked at. */
    public ?string $episode = null;

    /** Why the last run stopped short (an HTTP status, a step), for the log; null when it did not. */
    public ?string $reason = null;

    public function run(CarbonImmutable $now): string
    {
        $this->seconds = 0.0;
        $this->bytes = 0;
        $this->reason = null;

        if (QuietHours::active($now)) {
            return self::QUIET;
        }

        $episodes = $this->newestEpisodes();

        if ($episodes === []) {
            return self::NO_EPISODE;
        }

        try {
            $status = $this->server()->post($this->serverUrl().'/api/caster/podcast-transcript/status');
        } catch (ConnectionException) {
            return self::OFFLINE;
        }

        if (! $status->successful()) {
            return self::OFFLINE;
        }

        // The first of the feeds' newest episodes the server does not have yet: all podcasts come round.
        $episode = collect($episodes)->first(fn (array $e): bool => $status->json('guid') !== $e['guid']);

        if ($episode === null) {
            return self::DONE;
        }

        $this->episode = ($episode['podcast'] !== '' ? $episode['podcast'].': ' : '').$episode['title'];

        $started = microtime(true);
        [$text, $failure] = $this->transcribe($episode['url']);
        $this->seconds = microtime(true) - $started;

        if ($text === null) {
            return $failure;
        }

        $text = self::capped($text, max(1, (int) config('tv.commentary.podcast.max_bytes', 400 * 1024) - self::ENVELOPE_BYTES));
        $this->bytes = strlen($text);

        try {
            $upload = $this->server()->withBody((string) json_encode([
                'guid' => $episode['guid'],
                'title' => $episode['title'],
                'podcast' => $episode['podcast'],
                'episode_number' => $episode['episode_number'],
                'published_at' => $episode['published_at'],
                'transcript' => $text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json')->post($this->serverUrl().'/api/caster/podcast-transcript');
        } catch (ConnectionException) {
            return self::OFFLINE;
        }

        if ($upload->status() !== 201) {
            $this->reason = 'upload '.$upload->status();

            return self::FAILED;
        }

        return self::TRANSCRIBED;
    }

    /**
     * A transcript within `$bytes`, cut at a word.
     */
    public static function capped(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        $cut = mb_strcut($text, 0, $bytes, 'UTF-8');
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space));
    }

    /**
     * The newest episode of every transcript feed (user 2026-10-16: "echte Transkripte aus allen Podcasts von
     * EINUNDZWANZIG oder Re:set Blocktrainer oder Münzweg"), each with its podcast's name; the caller asks the
     * server per episode and transcribes the first one it does not have.
     *
     * @return list<array{guid: string, title: string, published_at: int, url: string, episode_number: int|null, podcast: string}>
     */
    private function newestEpisodes(): array
    {
        $episodes = [];

        foreach ((array) config('tv.commentary.chat.transcript_feeds', []) as $url) {
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                continue;
            }

            try {
                $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')->connectTimeout(5)->timeout(60)->get($url);
            } catch (Throwable) {
                continue;
            }

            $body = $response->body();

            if (! $response->successful() || strlen($body) > (int) config('tv.commentary.chat.podcast_max_bytes', 12 * 1024 * 1024)) {
                continue;
            }

            $episode = PodcastFeed::newestAudio($body);

            if ($episode === null || $episode['published_at'] <= 0) {
                continue;
            }

            $podcast = preg_match('/<title>(.*?)<\/title>/s', $body, $m) === 1
                ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : basename(parse_url($url, PHP_URL_HOST) ?: '');
            $episode['podcast'] = mb_substr($podcast !== '' ? $podcast : 'Podcast', 0, 80);
            $episodes[] = $episode;
        }

        return $episodes;
    }

    /**
     * The episode's text, or null and the outcome that stopped it (UNAVAILABLE: Whisper unreachable; FAILED: anything else).
     *
     * @return array{0: string|null, 1: string}
     */
    private function transcribe(string $url): array
    {
        $settings = (array) config('tv.commentary.podcast', []);
        $dir = sys_get_temp_dir().'/twentyone-podcast-'.bin2hex(random_bytes(6));

        try {
            File::ensureDirectoryExists($dir, 0700);
            $audio = $dir.'/episode.audio';

            $download = Http::connectTimeout(10)->timeout(900)->withOptions(['sink' => $audio])->get($url);
            if (! $download->successful() || ! is_file($audio) || filesize($audio) === 0 || filesize($audio) > (int) ($settings['max_audio_bytes'] ?? 400 * 1024 * 1024)) {
                $this->reason = 'download '.$download->status();

                return [null, self::FAILED];
            }

            $cut = Process::timeout(1_800)->run([
                'nice', '-n', '10', (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-i', $audio, '-vn', '-ac', '1', '-ar', '16000', '-f', 'segment', '-segment_time', (string) max(60, (int) ($settings['chunk_seconds'] ?? 600)),
                '-c:a', 'flac', $dir.'/part-%03d.flac',
            ]);
            File::delete($audio);

            $parts = glob($dir.'/part-*.flac') ?: [];
            sort($parts);

            if (! $cut->successful() || $parts === []) {
                $this->reason = 'ffmpeg '.$cut->exitCode();

                return [null, self::FAILED];
            }

            $texts = [];
            foreach ($parts as $part) {
                try {
                    $response = Http::asMultipart()->timeout((int) ($settings['transcribe_timeout_seconds'] ?? 1_800))
                        ->attach('file', (string) file_get_contents($part), basename($part))
                        ->post((string) ($settings['transcribe_url'] ?? ''), ['model' => 'whisper-1', 'language' => 'de', 'response_format' => 'json']);
                } catch (ConnectionException) {
                    $this->reason = 'whisper unreachable at '.basename($part);

                    return [null, self::UNAVAILABLE];
                } finally {
                    File::delete($part);
                }

                if (! $response->successful() || ! is_string($response->json('text'))) {
                    $this->reason = 'whisper '.$response->status().' at '.basename($part);

                    return [null, self::FAILED];
                }

                $texts[] = trim((string) $response->json('text'));
            }

            $text = trim(implode("\n", array_filter($texts, fn (string $text): bool => $text !== '')));

            return $text === '' ? [null, self::FAILED] : [$text, self::TRANSCRIBED];
        } catch (Throwable $exception) {
            $this->reason = $exception::class;

            return [null, self::FAILED];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function server(): PendingRequest
    {
        return Http::withToken((string) config('tv.commentary.fetch.token'))->acceptJson()->timeout(60);
    }

    private function serverUrl(): string
    {
        return rtrim((string) config('tv.commentary.voicestudio.server', ''), '/');
    }
}
