<?php

namespace App\Support\TwentyOne;

use App\Support\TwentyOne\Stream\FfmpegCommands;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether the 24/7 stream is on air right now, for the site around it (P20):
 * the header's LIVE badge, the floating player and the /live page.
 *
 * On air means the public playlist in `twentyone.stream.hls_dir` moved
 * within {@see freshSeconds()}. The supervisor rewrites it with every new
 * segment (PublicPlaylist::update(), every 6 s) and keeps it on a stop, so
 * an old modification time is the "off air" signal. Anything that cannot be
 * read (no directory, no file, a failing stat) counts as off air: the site
 * never offers a player for a stream that is not there.
 *
 * Viewers, title and summary are what the supervisor last announced in its
 * kind 30311, read from {@see ANNOUNCED_KEY} in the cache when the stream
 * process writes it there; without it they are null and the site shows no
 * count. Nothing here asks a relay: a page request never leaves the host.
 * The result is cached for {@see CACHE_SECONDS}, so a page that asks three
 * times (badge, player, page) and a busy minute stat the file rarely.
 */
final readonly class LiveStatus
{
    public const CACHE_KEY = 'twentyone.live.status';

    /**
     * What the stream process announced last, when it shares it:
     * `['viewers' => int|null, 'title' => string, 'summary' => string]`.
     */
    public const ANNOUNCED_KEY = 'twentyone.stream.announced';

    public const CACHE_SECONDS = 5;

    public function __construct(
        public bool $live,
        public ?int $viewers = null,
        public ?string $title = null,
        public ?string $summary = null,
    ) {}

    /**
     * Three segment lengths: one late segment does not take the badge down,
     * a stopped encoder does within 18 s (the supervisor's own watchdog).
     */
    public static function freshSeconds(): int
    {
        return 3 * FfmpegCommands::SEGMENT_SECONDS;
    }

    public static function offAir(): self
    {
        return new self(false);
    }

    /**
     * The status of this request: cached for a few seconds, read once per
     * request. A failing cache store reads the file directly.
     */
    public static function current(): self
    {
        // Kept on the request, not in once(): the browser tests' in-process server serves every request from one process.
        $attributes = request()->attributes;
        $memo = $attributes->get(self::CACHE_KEY);

        if ($memo instanceof self) {
            return $memo;
        }

        try {
            // fromArray() checks every field again: the cache is shared, the key is only ours by convention.
            $status = self::fromArray(Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => self::read()->toArray()));
        } catch (Throwable $e) {
            report($e);
            $status = self::read();
        }

        $attributes->set(self::CACHE_KEY, $status);

        return $status;
    }

    /**
     * The status from the disk, uncached. Never throws.
     */
    public static function read(?int $now = null): self
    {
        try {
            $playlist = self::playlistPath();
            clearstatcache(true, $playlist);
            $modifiedAt = @filemtime($playlist);

            if ($modifiedAt === false || ($now ?? time()) - $modifiedAt >= self::freshSeconds()) {
                return self::offAir();
            }

            $announced = Cache::get(self::ANNOUNCED_KEY);
            $announced = is_array($announced) ? $announced : [];
            $viewers = $announced['viewers'] ?? null;

            return new self(
                true,
                is_int($viewers) && $viewers >= 0 ? $viewers : null,
                self::text($announced['title'] ?? null),
                self::text($announced['summary'] ?? null),
            );
        } catch (Throwable $e) {
            report($e);

            return self::offAir();
        }
    }

    /**
     * The playlist on disk: its file name is the last path segment of the public URL, as the supervisor names it.
     */
    public static function playlistPath(): string
    {
        $name = basename((string) parse_url((string) config('twentyone.stream.public_url'), PHP_URL_PATH));

        return rtrim((string) config('twentyone.stream.hls_dir'), '/').'/'.($name !== '' ? $name : 'stream.m3u8');
    }

    /**
     * The URL players load; same origin in production (nginx serves hls_dir at /live/).
     */
    public static function playlistUrl(): string
    {
        return (string) config('twentyone.stream.public_url');
    }

    /**
     * @return array{live: bool, viewers: int|null, title: string|null, summary: string|null}
     */
    public function toArray(): array
    {
        return ['live' => $this->live, 'viewers' => $this->viewers, 'title' => $this->title, 'summary' => $this->summary];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function fromArray(array $data): self
    {
        $viewers = $data['viewers'] ?? null;

        return new self(
            ($data['live'] ?? false) === true,
            is_int($viewers) ? $viewers : null,
            self::text($data['title'] ?? null),
            self::text($data['summary'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 300) : null;
    }
}
