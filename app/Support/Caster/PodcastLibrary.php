<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The podcast replay's episodes on the server (plan "TWENTY ONE Radio", P12;
 * `tv.commentary.podcast_replay`): for every enabled source the newest
 * episode of its feed (PodcastFeed::newestAudio(), `title_pattern` picks one
 * show of a feed that carries several), downloaded into a private temp
 * directory, levelled to the voice's loudness (`voicestudio.loudness_lufs`:
 * one gain after an EBU R128 measurement, a limiter on the peaks) and encoded
 * once as mono Opus at the voice feed's rate by ffmpeg under `nice`, its
 * pauses found in the same pass (silencedetect: `pause_db` for
 * `pause_seconds`), then cut at pauses into chunks of about `chunk_seconds`
 * (plan()). The download goes in every case.
 *
 * Layout on the spool disk: `<dir>/<source>/<episode id>/chunk-NNN.opus` and
 * `<dir>/<source>/episode.json` naming the episode, its chunks, their lengths
 * and the pauses inside each (seconds into the chunk; the chunk's end is one
 * too). A newer episode replaces the old one once its chunks are complete; an
 * older one stays only while a clip of an airing still plays from it.
 *
 * Bounded: one episode per source (plus one still on air), downloads up to
 * `max_download_bytes`, the whole directory up to `max_bytes` (a new episode
 * that would not fit is dropped), and a disabled source's episodes are
 * deleted. Fails closed: a feed, download or ffmpeg that fails leaves the
 * stored episode as it was, and the source is tried again after
 * `check_minutes`.
 */
class PodcastLibrary
{
    public const STORED = 'stored';

    public const SAME = 'same';

    public const NOT_DUE = 'not_due';

    public const NO_EPISODE = 'no_episode';

    public const FEED_FAILED = 'feed_failed';

    public const DOWNLOAD_FAILED = 'download_failed';

    public const TRANSCODE_FAILED = 'transcode_failed';

    public const TOO_BIG = 'too_big';

    /** When a source's feed was read last (a timestamp, per source). */
    private const CHECKED = 'caster:podcast_replay:checked:';

    /**
     * What the last refresh measured per source (download, transcode, chunks, pauses), for the command's log line.
     *
     * @var array<string, array<string, int|float|string>>
     */
    public array $measured = [];

    /** The source refreshSource() is working on. */
    private string $measuring = '';

    /**
     * The settings of `tv.commentary.podcast_replay`.
     *
     * @return array<array-key, mixed>
     */
    public static function settings(): array
    {
        return (array) config('tv.commentary.podcast_replay', []);
    }

    public static function enabled(): bool
    {
        return config('tv.commentary.podcast_replay.enabled') === true;
    }

    /**
     * Whether a podcast may start at `$at`: inside the replay window (`window`, Berlin; user 2026-10-15: "Die Podcast
     * Wiederholungen sollen nur zwischen 24 Uhr und 09 Uhr morgens laufen"), one that passes midnight too. The same
     * `from` and `to`: no window, any time.
     */
    public static function airsAt(CarbonImmutable $at): bool
    {
        $window = (array) (self::settings()['window'] ?? []);
        $from = self::minuteOfDay((string) ($window['from'] ?? '00:00'));
        $to = self::minuteOfDay((string) ($window['to'] ?? '09:00'));
        $local = $at->setTimezone((string) config('tv.commentary.programme.timezone', 'Europe/Berlin'));
        $minute = $local->hour * 60 + $local->minute;

        if ($from === $to) {
            return true;
        }

        return $from < $to ? $minute >= $from && $minute < $to : $minute >= $from || $minute < $to;
    }

    /** `HH:MM` as minutes since midnight. */
    private static function minuteOfDay(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hour * 60 + $minute;
    }

    /**
     * The enabled sources by key, each with its feed and slot; none while the replay is off.
     *
     * @return array<string, array{name: string, spoken: string, feed: string, title_pattern: string|null, require_pattern: string|null, exclude_pattern: string|null, starts_at: string}>
     */
    public static function sources(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $sources = [];

        foreach ((array) (self::settings()['sources'] ?? []) as $key => $source) {
            if (! is_string($key) || preg_match('/^[a-z0-9_-]{1,16}$/', $key) !== 1 || ! is_array($source) || ($source['enabled'] ?? false) !== true
                || ! is_string($source['feed'] ?? null) || ! str_starts_with($source['feed'], 'https://')) {
                continue;
            }

            $sources[$key] = [
                'name' => is_string($source['name'] ?? null) ? $source['name'] : $key,
                'spoken' => is_string($source['spoken'] ?? null) ? $source['spoken'] : (is_string($source['name'] ?? null) ? $source['name'] : $key),
                'feed' => $source['feed'],
                'title_pattern' => is_string($source['title_pattern'] ?? null) && $source['title_pattern'] !== '' ? $source['title_pattern'] : null,
                'require_pattern' => is_string($source['require_pattern'] ?? null) && $source['require_pattern'] !== '' ? $source['require_pattern'] : null,
                'exclude_pattern' => is_string($source['exclude_pattern'] ?? null) && $source['exclude_pattern'] !== '' ? $source['exclude_pattern'] : null,
                'starts_at' => is_string($source['starts_at'] ?? null) ? $source['starts_at'] : '14:03',
            ];
        }

        return $sources;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('tv.commentary.spool.disk', 'local'));
    }

    public function dir(): string
    {
        return trim((string) (self::settings()['dir'] ?? 'caster/podcasts'), '/');
    }

    /**
     * The stored episode of a source: its guid, title, notes, length and chunks (path on the spool disk, seconds,
     * pauses), or null when there is none or its chunks are not all there.
     *
     * @return array{guid: string, title: string, episode_number?: int|null, notes?: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}|null
     */
    public function episode(string $source): ?array
    {
        $file = $this->dir().'/'.$source.'/episode.json';
        $meta = $this->disk()->exists($file) ? json_decode((string) $this->disk()->get($file), true) : null;

        if (! is_array($meta) || ! is_string($meta['guid'] ?? null) || ! is_array($meta['chunks'] ?? null) || $meta['chunks'] === []) {
            return null;
        }

        foreach ($meta['chunks'] as $chunk) {
            if (! is_array($chunk) || ! is_string($chunk['path'] ?? null) || ! $this->disk()->exists($chunk['path'])) {
                return null;
            }
        }

        /** @var array{guid: string, title: string, episode_number?: int|null, notes?: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>} $meta */
        return $meta;
    }

    /**
     * Every stored episode of a source whose chunks are all there (the current one and the night loop's), newest
     * first by publication, each once.
     *
     * @return list<array{guid: string, title: string, episode_number?: int|null, notes?: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}>
     */
    public function episodes(string $source): array
    {
        $episodes = [];
        $files = [$this->dir().'/'.$source.'/episode.json'];

        foreach ($this->disk()->directories($this->dir().'/'.$source) as $directory) {
            $files[] = $directory.'/meta.json';
        }

        foreach ($files as $file) {
            $meta = $this->disk()->exists($file) ? json_decode((string) $this->disk()->get($file), true) : null;

            if (! is_array($meta) || ! is_string($meta['guid'] ?? null) || isset($episodes[$meta['guid']]) || ! is_array($meta['chunks'] ?? null) || $meta['chunks'] === []) {
                continue;
            }

            foreach ($meta['chunks'] as $chunk) {
                if (! is_array($chunk) || ! is_string($chunk['path'] ?? null) || ! $this->disk()->exists($chunk['path'])) {
                    continue 2;
                }
            }

            // An episode stored without its publication time sorts last, as the oldest.
            $meta['published_at'] = (int) ($meta['published_at'] ?? 0);
            $episodes[$meta['guid']] = $meta;
        }

        /** @var list<array{guid: string, title: string, episode_number?: int|null, notes?: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}> $sorted */
        $sorted = array_values($episodes);
        usort($sorted, fn (array $a, array $b): int => $b['published_at'] <=> $a['published_at']);

        return $sorted;
    }

    /**
     * The episode directories the night loop keeps for a source: its newest `episodes + 1` (none while it is off).
     *
     * @return list<string>
     */
    private function nightDirectories(string $source): array
    {
        $keep = PodcastNight::episodeCount($source);

        return $keep === 0 ? [] : array_map(fn (array $episode): string => dirname($episode['chunks'][0]['path']), array_slice($this->episodes($source), 0, $keep + 1));
    }

    /**
     * Reads every enabled source whose last read is `check_minutes` ago (all with `$force`), stores a newer episode
     * and trims the directory; returns what happened per source.
     *
     * @return array<string, string>
     */
    public function refresh(CarbonImmutable $now, bool $force = false): array
    {
        $outcomes = [];
        $every = 60 * max(1, (int) (self::settings()['check_minutes'] ?? 60));

        foreach (self::sources() as $key => $source) {
            $checked = Cache::get(self::CHECKED.$key);

            if (! $force && is_int($checked) && $now->getTimestamp() - $checked < $every) {
                $outcomes[$key] = self::NOT_DUE;

                continue;
            }

            Cache::put(self::CHECKED.$key, $now->getTimestamp(), 2 * $every);
            $outcomes[$key] = $this->refreshSource($key, $source);
        }

        $this->bound();

        return $outcomes;
    }

    /**
     * One source: the newest episode of its feed, stored when it is not the one already here.
     *
     * @param  array{name: string, spoken: string, feed: string, title_pattern: string|null, require_pattern: string|null, exclude_pattern: string|null, starts_at: string}  $source
     */
    public function refreshSource(string $key, array $source): string
    {
        $settings = self::settings();
        $this->measuring = $key;
        $this->measured[$key] = [];

        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')->connectTimeout(5)->timeout(60)->get($source['feed']);
        } catch (Throwable) {
            return self::FEED_FAILED;
        }

        if (! $response->successful() || strlen($response->body()) > (int) ($settings['feed_max_bytes'] ?? 12 * 1024 * 1024)) {
            return self::FEED_FAILED;
        }

        // The night loop (PodcastNight) plays the newest few: those are stored too, after the newest.
        $night = PodcastNight::episodeCount($key);
        $episodes = PodcastFeed::newestAudios($response->body(), $source['title_pattern'], max(1, $night), $source['require_pattern'], $source['exclude_pattern']);

        if ($episodes === []) {
            return self::NO_EPISODE;
        }

        $outcome = ($this->episode($key)['guid'] ?? null) === $episodes[0]['guid'] ? self::SAME : $this->store($key, $episodes[0], current: true);

        if ($night > 1 && in_array($outcome, [self::SAME, self::STORED], true)) {
            $this->prefetch($key, array_slice($episodes, 1));
        }

        return $outcome;
    }

    /**
     * Stores the night loop's older episodes that are not here yet, newest first, at most `night.prefetch_per_run`
     * per refresh; stops at the first one that fails or would not fit.
     *
     * @param  list<array{guid: string, title: string, published_at: int, url: string, episode_number: int|null, notes: string}>  $episodes
     */
    private function prefetch(string $key, array $episodes): void
    {
        $stored = array_column($this->episodes($key), 'guid');
        $left = max(0, (int) (self::settings()['night']['prefetch_per_run'] ?? 6));
        $measured = $this->measured[$key];
        $prefetched = 0;

        foreach ($episodes as $episode) {
            if (in_array($episode['guid'], $stored, true)) {
                continue;
            }

            if ($left-- <= 0) {
                break;
            }

            $this->measured[$key] = [];
            $outcome = $this->store($key, $episode, current: false);
            $measured['night_'.$prefetched] = $outcome.' '.json_encode($this->measured[$key]);

            if ($outcome !== self::STORED) {
                break;
            }

            $prefetched++;
        }

        $measured['night_stored'] = $prefetched;
        $this->measured[$key] = $measured;
    }

    /**
     * Downloads, levels and cuts one episode into its own directory with a `meta.json`; `$current` makes it the
     * source's episode (`episode.json`, the daytime airing).
     *
     * @param  array{guid: string, title: string, published_at: int, url: string, episode_number: int|null, notes: string}  $episode
     */
    private function store(string $key, array $episode, bool $current): string
    {
        $settings = self::settings();
        $id = substr(sha1($episode['guid']), 0, 12);
        $target = $this->dir().'/'.$key.'/'.$id;
        $temp = sys_get_temp_dir().'/twentyone-podcast-replay-'.bin2hex(random_bytes(6));
        $started = microtime(true);

        try {
            File::ensureDirectoryExists($temp, 0700);
            $audio = $temp.'/episode.audio';
            $max = (int) ($settings['max_download_bytes'] ?? 400 * 1024 * 1024);

            try {
                $download = Http::connectTimeout(10)->timeout((int) ($settings['download_timeout_seconds'] ?? 900))->withOptions(['sink' => $audio])->get($episode['url']);
            } catch (Throwable) {
                return self::DOWNLOAD_FAILED;
            }

            clearstatcache(true, $audio);

            if (! $download->successful() || ! is_file($audio) || filesize($audio) === 0) {
                return self::DOWNLOAD_FAILED;
            }

            if (filesize($audio) > $max) {
                return self::TOO_BIG;
            }

            $this->measured[$this->measuring]['download_bytes'] = (int) filesize($audio);
            $this->measured[$this->measuring]['download_seconds'] = round(microtime(true) - $started, 1);

            $this->disk()->deleteDirectory($target);
            $this->disk()->makeDirectory($target);
            $chunks = $this->transcode($audio, $temp, $target);
            File::delete($audio);

            if ($chunks === null) {
                $this->disk()->deleteDirectory($target);

                return self::TRANSCODE_FAILED;
            }

            $meta = [
                'guid' => $episode['guid'],
                'title' => $episode['title'],
                'episode_number' => $episode['episode_number'],
                // What Markus and Sirius may say about it (PodcastScripts).
                'notes' => $episode['notes'],
                'published_at' => $episode['published_at'],
                'source' => $key,
                'id' => $id,
                'seconds' => round(array_sum(array_column($chunks, 'seconds')), 3),
                'stored_at' => CarbonImmutable::now()->toIso8601String(),
                'chunks' => $chunks,
            ];
            $this->measured[$this->measuring]['seconds'] = $meta['seconds'];
            $this->measured[$this->measuring]['chunks'] = count($chunks);
            $this->measured[$this->measuring]['bytes'] = array_sum(array_column($chunks, 'bytes'));
            $this->measured[$this->measuring]['total_seconds'] = round(microtime(true) - $started, 1);

            // The library may not outgrow `max_bytes`: what no airing plays from goes first, the episode this one replaces
            // counts as gone unless an airing still plays from it (or the night loop keeps it), else the new episode
            // itself is dropped.
            $this->bound(keep: $target);
            $old = $current ? $this->episode($key) : null;
            $oldDir = $old === null ? null : dirname($old['chunks'][0]['path']);
            $freed = $oldDir === null || in_array($oldDir, [...$this->playingDirectories(), ...$this->nightDirectories($key)], true) ? 0 : $this->directoryBytes($oldDir);
            $json = (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // The meta files count too (episode.json replaces one of the same kind, so meta.json alone is new).
            if ($this->bytes() - $freed + strlen($json) > (int) ($settings['max_bytes'] ?? 1024 * 1024 * 1024)) {
                $this->disk()->deleteDirectory($target);

                return self::TOO_BIG;
            }

            $this->disk()->put($target.'/meta.json', $json);

            if ($current) {
                $this->disk()->put($this->dir().'/'.$key.'/episode.json', $json);
            }

            $this->bound();

            return self::STORED;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /**
     * Levels and encodes the episode in one ffmpeg pass that also finds its pauses, then cuts the Opus into chunks at
     * pauses (plan()) without encoding again. Null when ffmpeg fails.
     *
     * @return list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>|null
     */
    private function transcode(string $audio, string $temp, string $target): ?array
    {
        $settings = self::settings();
        $ffmpeg = (string) config('twentyone.stream.ffmpeg', 'ffmpeg');
        $rate = (int) config('tv.commentary.mix.sample_rate', 24000);
        $lufs = (int) config('tv.commentary.voicestudio.loudness_lufs', -16);
        $full = $temp.'/episode.opus';
        $timeout = (int) ($settings['transcode_timeout_seconds'] ?? 1_800);
        $started = microtime(true);
        $mono = '[0:a]aresample='.$rate.',aformat=channel_layouts=mono';

        // First the episode's loudness (EBU R128). Measured 2026-10-12 on episode #335: a single-pass loudnorm aiming at
        // -16 LUFS left the chunks at -20.5 to -21.5 LUFS, 5 dB under the voice, and took 207 s at 192 kHz.
        $measure = Process::timeout($timeout)->env(ChildEnvironment::withoutSecrets())->run([
            'nice', '-n', '10', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'info', '-i', $audio, '-vn',
            '-filter_complex', $mono.',ebur128=framelog=quiet[out]', '-map', '[out]', '-f', 'null', '-',
        ]);
        $loudness = $measure->successful() ? self::loudness($measure->errorOutput()) : null;

        if ($loudness === null) {
            return null;
        }

        // Then one gain to the voice's loudness, a limiter for the peaks, and the pauses found in the same pass.
        $gain = round(max(-20.0, min(20.0, $lufs - $loudness)), 2);
        $this->measured[$this->measuring]['loudness_lufs'] = $loudness;
        $this->measured[$this->measuring]['gain_db'] = $gain;
        $filter = $mono.',volume='.$gain.'dB,alimiter=limit=0.84:level=false,asplit=2[voice][probe];'
            .'[probe]silencedetect=noise='.(int) ($settings['pause_db'] ?? -35).'dB:d='.max(0.1, (float) ($settings['pause_seconds'] ?? 0.35)).'[quiet]';

        $encode = Process::timeout($timeout)->env(ChildEnvironment::withoutSecrets())->run([
            'nice', '-n', '10', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'info', '-i', $audio, '-vn',
            '-filter_complex', $filter,
            '-map', '[voice]', '-c:a', 'libopus', '-b:a', ((int) ($settings['opus_kbps'] ?? 32)).'k', '-application', 'voip', '-f', 'ogg', $full,
            '-map', '[quiet]', '-f', 'null', '-',
        ]);

        if (! $encode->successful() || ! is_file($full)) {
            return null;
        }

        $this->measured[$this->measuring]['transcode_seconds'] = round(microtime(true) - $started, 1);
        $pauses = self::pauses($encode->errorOutput());
        $duration = self::duration($encode->errorOutput());

        if ($duration <= 0) {
            return null;
        }

        $plan = self::plan($duration, $pauses, (float) ($settings['chunk_seconds'] ?? 300));
        $times = array_map(fn (array $chunk): string => sprintf('%.3F', $chunk['from']), array_slice($plan, 1));
        $directory = $this->disk()->path($target);
        $list = $temp.'/chunks.csv';

        $cut = Process::timeout($timeout)->env(ChildEnvironment::withoutSecrets())->run([
            'nice', '-n', '10', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $full, '-c', 'copy', '-map', '0:a',
            '-f', 'segment', '-segment_format', 'ogg', '-segment_list', $list, '-segment_list_type', 'csv',
            ...($times === [] ? [] : ['-segment_times', implode(',', $times)]),
            $directory.'/chunk-%03d.opus',
        ]);
        File::delete($full);

        if (! $cut->successful() || ! is_file($list)) {
            return null;
        }

        $this->measured[$this->measuring]['pauses'] = count($pauses);
        $this->measured[$this->measuring]['max_pause_gap'] = self::maxGap($pauses, $duration);

        return self::chunks((string) file_get_contents($list), $pauses, $target, fn (string $path): int => (int) $this->disk()->size($path));
    }

    /**
     * The chunks ffmpeg's segment list names (file, start, end per line), each with the pauses inside it.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     * @param  callable(string): int  $size
     * @return list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>|null
     */
    public static function chunks(string $csv, array $pauses, string $target, callable $size): ?array
    {
        $chunks = [];

        foreach (preg_split('/\R/', trim($csv)) ?: [] as $line) {
            $fields = str_getcsv($line, escape: '');

            if (count($fields) < 3 || preg_match('/^chunk-\d{3}\.opus$/', basename((string) $fields[0])) !== 1) {
                continue;
            }

            [$from, $to] = [(float) $fields[1], (float) $fields[2]];
            $seconds = round($to - $from, 3);

            if ($seconds <= 0) {
                continue;
            }

            $inside = [];
            foreach ($pauses as $pause) {
                $middle = ($pause['start'] + $pause['end']) / 2;

                if ($middle > $from + 0.5 && $middle < $to - 0.5) {
                    $inside[] = round($middle - $from, 3);
                }
            }

            $path = $target.'/'.basename((string) $fields[0]);
            $chunks[] = ['path' => $path, 'seconds' => $seconds, 'bytes' => $size($path), 'pauses' => [...$inside, $seconds]];
        }

        return $chunks === [] ? null : $chunks;
    }

    /** The integrated loudness ebur128 logged in its summary (LUFS), null when there is none. */
    public static function loudness(string $log): ?float
    {
        $summary = strrpos($log, 'Summary:');

        return $summary !== false && preg_match('/I:\s*(-?[\d.]+)\s*LUFS/', substr($log, $summary), $match) === 1 && (float) $match[1] > -70.0 ? (float) $match[1] : null;
    }

    /**
     * The pauses silencedetect logged: where each starts and ends (seconds into the episode).
     *
     * @return list<array{start: float, end: float}>
     */
    public static function pauses(string $log): array
    {
        preg_match_all('/silence_(start|end):\s*(-?[\d.]+)/', $log, $matches, PREG_SET_ORDER);
        $pauses = [];
        $start = null;

        foreach ($matches as $match) {
            if ($match[1] === 'start') {
                $start = max(0.0, (float) $match[2]);
            } elseif ($start !== null) {
                $pauses[] = ['start' => $start, 'end' => (float) $match[2]];
                $start = null;
            }
        }

        return $pauses;
    }

    /** The length ffmpeg reached in the log (the last `time=` of the encode, else the input's `Duration:`), 0 when none. */
    public static function duration(string $log): float
    {
        $seconds = fn (string $h, string $m, string $s): float => 3600 * (int) $h + 60 * (int) $m + (float) $s;

        if (preg_match_all('/time=(\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $log, $times, PREG_SET_ORDER) > 0) {
            $last = $times[count($times) - 1];

            return $seconds($last[1], $last[2], $last[3]);
        }

        return preg_match('/Duration:\s*(\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $log, $match) === 1 ? $seconds($match[1], $match[2], $match[3]) : 0.0;
    }

    /**
     * Where the chunks of an episode of `$duration` start and end: each about `$chunk` seconds, cut at the pause
     * whose middle lies nearest its target within 80 to 120 % of `$chunk` after the cut before (straight at the
     * target when no pause lies there); a rest under 40 % of `$chunk` joins the chunk before it.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     * @return list<array{from: float, to: float}>
     */
    public static function plan(float $duration, array $pauses, float $chunk): array
    {
        $chunk = max(30.0, $chunk);
        $middles = array_map(fn (array $pause): float => ($pause['start'] + $pause['end']) / 2, $pauses);
        $plan = [];
        $from = 0.0;

        while ($duration - $from > 1.4 * $chunk) {
            $target = $from + $chunk;
            $candidates = array_values(array_filter($middles, fn (float $middle): bool => $middle >= $from + 0.8 * $chunk && $middle <= $from + 1.2 * $chunk));
            usort($candidates, fn (float $a, float $b): int => abs($a - $target) <=> abs($b - $target));
            $to = round($candidates[0] ?? $target, 3);
            $plan[] = ['from' => $from, 'to' => $to];
            $from = $to;
        }

        $plan[] = ['from' => $from, 'to' => round($duration, 3)];

        return $plan;
    }

    /**
     * The longest stretch without a pause (seconds), the edges of the episode included.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     */
    public static function maxGap(array $pauses, float $duration): float
    {
        $previous = 0.0;
        $gap = 0.0;

        foreach ($pauses as $pause) {
            $middle = ($pause['start'] + $pause['end']) / 2;
            $gap = max($gap, $middle - $previous);
            $previous = $middle;
        }

        return round(max($gap, $duration - $previous), 1);
    }

    /**
     * The episode directories a clip in the spool plays from (an airing on air or still to come).
     *
     * @return list<string>
     */
    private function playingDirectories(): array
    {
        return array_values(array_unique(array_map(fn (string $path): string => dirname($path), CasterClip::query()->where('path', 'like', $this->dir().'/%')->pluck('path')->all())));
    }

    private function directoryBytes(string $directory): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) $this->disk()->size($file), $this->disk()->allFiles($directory)));
    }

    /** Bytes of every file in the library. */
    public function bytes(): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) $this->disk()->size($file), $this->disk()->allFiles($this->dir())));
    }

    /**
     * Deletes what the library no longer needs: the episodes of sources that are off or gone, and every episode
     * directory that is neither its source's current one, nor `$keep`, nor played from by a clip in the spool.
     */
    public function bound(?string $keep = null): void
    {
        $sources = self::sources();
        $playing = $this->playingDirectories();

        foreach ($this->disk()->directories($this->dir()) as $sourceDir) {
            $key = basename($sourceDir);
            $current = isset($sources[$key]) ? $this->episode($key) : null;
            $currentDir = $current === null ? null : dirname($current['chunks'][0]['path']);
            $night = isset($sources[$key]) ? $this->nightDirectories($key) : [];

            foreach ($this->disk()->directories($sourceDir) as $episodeDir) {
                if ($episodeDir !== $currentDir && $episodeDir !== $keep && ! in_array($episodeDir, $playing, true) && ! in_array($episodeDir, $night, true)) {
                    $this->disk()->deleteDirectory($episodeDir);
                }
            }

            if (! isset($sources[$key]) && $this->disk()->directories($sourceDir) === []) {
                $this->disk()->deleteDirectory($sourceDir);
            }
        }
    }
}
