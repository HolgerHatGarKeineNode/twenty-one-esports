<?php

namespace App\Support\TwentyOne\Stream;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * The order the stream plays its music in, as an ffconcat list.
 *
 * Files are named `<title>__v<n>.m4a`; several versions of one title exist.
 * Rule (docs/plans/…stream-live-scene.md, "Musik"): a random order in which
 * two consecutive files never share a title, also across the end of the list,
 * because ffmpeg loops it (`-stream_loop -1`). Each pass plays every file
 * once, in a fresh order.
 *
 * Instrumentals (a folder of their own, same naming) alternate with the
 * vocal tracks: `interleave()` puts one after every vocal track.
 */
final class MusicPlaylist
{
    /** Random walks tried per pass before giving up. */
    private const ATTEMPTS = 50;

    /**
     * The title part of `<title>__v<n>.m4a`; the whole name without extension otherwise.
     */
    public static function title(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return explode('__v', $name)[0];
    }

    /**
     * `$passes` passes over `$files`, joined so no title repeats back to back,
     * including from the last entry to the first.
     *
     * Each pass is built greedily: pick a random title other than the previous
     * one, except when a title must be placed now because the rest could not
     * separate its versions any more (then it is forced). This never dead-ends
     * when an order exists at all.
     *
     * @param  list<string>  $files
     * @return list<string>
     *
     * @throws InvalidArgumentException when no valid order exists (one title has more than half the files)
     */
    public static function order(array $files, int $passes, Randomizer $random = new Randomizer): array
    {
        $byTitle = [];

        foreach ($files as $file) {
            $byTitle[self::title($file)][] = $file;
        }

        $largest = $byTitle === [] ? 0 : max(array_map('count', $byTitle));

        if ($files === [] || count($byTitle) < 2 || $largest > intdiv(count($files), 2)) {
            throw new InvalidArgumentException('The music files cannot be ordered without repeating a title back to back.');
        }

        // The last pass may be boxed in by both ends (it follows the previous
        // pass and wraps to the first); then the whole list is drawn again.
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $order = self::build($byTitle, max(1, $passes), $random);

            if ($order !== null) {
                return $order;
            }
        }

        throw new InvalidArgumentException('No music order found without repeating a title back to back.');
    }

    /**
     * @param  array<string, list<string>>  $byTitle
     * @return list<string>|null
     */
    private static function build(array $byTitle, int $passes, Randomizer $random): ?array
    {
        $order = [];

        for ($pass = 0; $pass < $passes; $pass++) {
            $candidate = null;

            for ($try = 0; $try < self::ATTEMPTS && $candidate === null; $try++) {
                $previous = $order === [] ? null : self::title($order[count($order) - 1]);
                $candidate = self::pass($byTitle, $previous, $random);

                // The list wraps: its last title must differ from its first.
                if ($candidate !== null && $pass === $passes - 1
                    && self::title($candidate[count($candidate) - 1]) === self::title(($order === [] ? $candidate : $order)[0])) {
                    $candidate = null;
                }
            }

            if ($candidate === null) {
                return null;
            }

            array_push($order, ...$candidate);
        }

        return $order;
    }

    /**
     * The vocal order with one instrumental after every vocal track (vocal,
     * instrumental, vocal, …). Instrumentals are drawn from a shuffled bag
     * that refills when empty, so all of them come round before one repeats.
     * An instrumental never shares its title with the vocal before or after
     * it (several exist in both forms: the same tune twice in a row), nor
     * with the previous instrumental; the list wraps like `order()`.
     *
     * A slot no instrumental fits (possible only with at most two titles,
     * all of which are also sung) stays empty rather than failing.
     *
     * @param  list<string>  $vocals  a valid `order()`
     * @param  list<string>  $instrumentals
     * @return list<string>
     */
    public static function interleave(array $vocals, array $instrumentals, Randomizer $random = new Randomizer): array
    {
        if ($instrumentals === []) {
            return $vocals;
        }

        $count = count($vocals);
        $bag = [];
        $previous = null;
        $list = [];

        foreach ($vocals as $index => $vocal) {
            $neighbours = [self::title($vocal), self::title($vocals[($index + 1) % $count])];
            $pick = null;

            // Strict first (not the previous instrumental either), then only the neighbours.
            foreach ([[...$neighbours, $previous], $neighbours] as $avoid) {
                foreach ([false, true] as $refill) {
                    if ($refill || $bag === []) {
                        $bag = [...$bag, ...$random->shuffleArray($instrumentals)];
                    }

                    foreach ($bag as $key => $file) {
                        if (! in_array(self::title($file), $avoid, true)) {
                            $pick = $file;
                            unset($bag[$key]);
                            $bag = array_values($bag);

                            break 3;
                        }
                    }
                }
            }

            // No instrumental fits here (few titles, all also sung): the slot stays empty, two vocal
            // tracks follow each other as in order(), and the daemon never dies over the music.
            $list[] = $vocal;

            if ($pick !== null) {
                $list[] = $pick;
                $previous = self::title($pick);
            }
        }

        return $list;
    }

    /**
     * No control characters: a newline would end the ffconcat `file` line
     * and start a directive of its own.
     */
    public static function isSafePath(string $file): bool
    {
        return preg_match('/\p{C}/u', $file) === 0;
    }

    /**
     * An ffconcat file for `-f concat -safe 0` with absolute paths.
     *
     * @param  list<string>  $files
     */
    public static function ffconcat(array $files): string
    {
        $lines = ['ffconcat version 1.0'];

        foreach ($files as $file) {
            if (! self::isSafePath($file)) {
                throw new InvalidArgumentException('A music path with a control character cannot go into an ffconcat list.');
            }

            $lines[] = "file '".str_replace("'", "'\\''", $file)."'";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One pass: every file once, never the same title twice in a row.
     *
     * @param  array<string, list<string>>  $byTitle
     * @return list<string>|null null when this random walk ran into a dead end
     */
    private static function pass(array $byTitle, ?string $previous, Randomizer $random): ?array
    {
        $remaining = array_map(fn (array $versions): array => $random->shuffleArray($versions), $byTitle);
        $left = array_sum(array_map('count', $remaining));
        $pass = [];

        while ($left > 0) {
            $allowed = array_keys(array_filter($remaining, fn (array $versions, string $title): bool => $versions !== [] && $title !== $previous, ARRAY_FILTER_USE_BOTH));
            // A title holding more than half of what is left must go now, or it cannot be separated.
            $forced = array_values(array_filter($allowed, fn (string $title): bool => 2 * count($remaining[$title]) > $left));

            if ($allowed === []) {
                return null;
            }

            $title = $forced !== [] ? $forced[0] : $allowed[$random->getInt(0, count($allowed) - 1)];

            $pass[] = array_shift($remaining[$title]);
            $previous = $title;
            $left--;
        }

        return $pass;
    }
}
