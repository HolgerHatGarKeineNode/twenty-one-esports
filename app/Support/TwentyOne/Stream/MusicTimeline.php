<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Random\Randomizer;
use Throwable;

/**
 * The stream's music as one timeline on the wall clock, kept in a file, so a
 * new encoder (a mode switch, a watchdog restart, a deploy) goes on with the
 * track that is playing, at the second it is at, instead of a fresh shuffle
 * from its first track.
 *
 * The timeline is a row of sets. A set plays every vocal track once, in a
 * random order, with `perVocal` instrumentals after each (MusicPlaylist:
 * no title twice in a row, instrumentals from a bag that runs empty before
 * one repeats). When a set has played, the next one is a new shuffle; its
 * first track never has the title the last one had.
 *
 * Starts are cumulative real durations (ffprobe, kept per file by size and
 * mtime; `fallbackSeconds` when a file cannot be read). A changed set of
 * files (added, removed) starts a new timeline at once. Played entries older
 * than an hour are dropped, so the file stays small.
 */
final class MusicTimeline
{
    /** How far back played entries are kept (seconds). */
    private const KEEP_PLAYED = 3600;

    /** Shuffles tried for a set whose first title must differ from the last one played. */
    private const ATTEMPTS = 20;

    /** @var array<string, array{0: int, 1: int, 2: float}> per file: size, mtime, seconds (the probes to keep) */
    private array $probed = [];

    /**
     * @param  Closure(string): (float|null)  $probe  a file's duration in seconds, null when unreadable
     */
    public function __construct(
        private string $path,
        private Closure $probe,
        private float $fallbackSeconds = 180,
    ) {}

    /**
     * The entries from now on, covering at least `$hours`; the first one starts
     * `inpoint` seconds into its file.
     *
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @return array{files: list<string>, inpoint: float, restarted: bool}
     */
    public function from(array $vocals, array $instrumentals, int $perVocal, float $now, float $hours, Randomizer $random = new Randomizer): array
    {
        $state = $this->read();
        $fingerprint = self::fingerprint($vocals, $instrumentals, $perVocal);
        $durations = $this->durations([...$vocals, ...$instrumentals], $state['durations'] ?? []);
        $restarted = ($state['fingerprint'] ?? null) !== $fingerprint || ($state['entries'] ?? []) === [];

        /** @var list<array{0: string, 1: float}> $entries */
        $entries = $restarted ? [] : $state['entries'];
        $anchor = $restarted ? $now : (float) $state['anchor'];

        // Drop what played more than an hour ago, moving the anchor with it.
        while ($entries !== [] && $anchor + $entries[0][1] < $now - self::KEEP_PLAYED) {
            $anchor += array_shift($entries)[1];
        }

        $end = $anchor + array_sum(array_column($entries, 1));

        // A timeline that ended long ago (the stream was off) starts again now.
        if ($end < $now) {
            [$entries, $anchor, $end] = [[], $now, $now];
        }

        while ($end < $now + 3600 * $hours) {
            $last = $entries === [] ? null : MusicPlaylist::title($entries[count($entries) - 1][0]);

            foreach ($this->set($vocals, $instrumentals, $perVocal, $last, $random) as $file) {
                $entries[] = [$file, $durations[$file]];
                $end += $durations[$file];
            }
        }

        $start = $anchor;
        $index = 0;

        while ($start + $entries[$index][1] <= $now) {
            $start += $entries[$index][1];
            $index++;
        }

        $this->write(['fingerprint' => $fingerprint, 'anchor' => $anchor, 'entries' => $entries, 'durations' => $this->probed]);

        return ['files' => array_column(array_slice($entries, $index), 0), 'inpoint' => round($now - $start, 3), 'restarted' => $restarted];
    }

    /**
     * One set: every vocal once with instrumentals after each; a first title
     * other than `$last` where a shuffle allows it.
     *
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @return list<string>
     */
    private function set(array $vocals, array $instrumentals, int $perVocal, ?string $last, Randomizer $random): array
    {
        $set = [];

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $set = MusicPlaylist::interleave(MusicPlaylist::order($vocals, 1, $random), $instrumentals, $random, $perVocal);

            if ($last === null || MusicPlaylist::title($set[0]) !== $last) {
                return $set;
            }
        }

        return $set;
    }

    /**
     * The duration of every file, from the kept probes where size and mtime
     * still match, probed again otherwise.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $known
     * @return array<string, float>
     */
    private function durations(array $files, array $known): array
    {
        $durations = [];
        $kept = [];

        foreach ($files as $file) {
            $size = (int) @filesize($file);
            $mtime = (int) @filemtime($file);
            $entry = $known[$file] ?? null;

            if (is_array($entry) && ($entry[0] ?? null) === $size && ($entry[1] ?? null) === $mtime && is_numeric($entry[2] ?? null) && $entry[2] > 0) {
                $seconds = (float) $entry[2];
            } else {
                try {
                    $seconds = ($this->probe)($file);
                } catch (Throwable) {
                    $seconds = null;
                }

                $seconds = $seconds !== null && $seconds > 0 ? $seconds : $this->fallbackSeconds;
            }

            $durations[$file] = $seconds;
            $kept[$file] = [$size, $mtime, $seconds];
        }

        $this->probed = $kept;

        return $durations;
    }

    /**
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     */
    private static function fingerprint(array $vocals, array $instrumentals, int $perVocal): string
    {
        sort($vocals);
        sort($instrumentals);

        return sha1(json_encode([$vocals, $instrumentals, $perVocal], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        try {
            $state = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        } catch (Throwable) {
            $state = null;
        }

        if (! is_array($state) || ! is_numeric($state['anchor'] ?? null) || ! is_array($state['entries'] ?? null)) {
            return [];
        }

        foreach ($state['entries'] as $entry) {
            if (! is_array($entry) || ! is_string($entry[0] ?? null) || ! is_numeric($entry[1] ?? null) || $entry[1] <= 0) {
                return [];
            }
        }

        $state['entries'] = array_map(fn (array $entry): array => [$entry[0], (float) $entry[1]], array_values($state['entries']));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }

        PlaylistWriter::writeAtomically($this->path, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
