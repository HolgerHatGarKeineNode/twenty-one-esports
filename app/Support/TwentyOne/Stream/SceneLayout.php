<?php

namespace App\Support\TwentyOne\Stream;

/**
 * The layout helpers of resources/views/stream/scene.blade.php.
 *
 * They live here and not as closures inside the view on purpose: the
 * supervisor renders the view once a second for days, and without the CLI
 * opcache (opcache.enable_cli=0, the PHP default and the prod setting) every
 * include of a compiled view compiles its closures again and never frees
 * them. Measured 2026-09-27: 2.6 KB per render, ~9 MB an hour, the stream
 * daemon at 289 MB RSS after 21.6 h. The view takes these as first-class
 * callables (`SceneLayout::fit(...)`), which do not compile anything.
 */
final class SceneLayout
{
    /**
     * Code points the two JetBrains Mono files cover (latin + latin-ext subsets, the same two
     * the site ships). Anything else (emoji, Cyrillic, CJK, control characters) would render
     * as a missing-glyph box, so it is dropped.
     */
    private const COVERED = '/[^\x{0020}-\x{007E}\x{00A0}-\x{0131}\x{0134}-\x{017F}\x{018F}\x{0192}\x{01A0}-\x{01A1}\x{01AF}-\x{01B0}\x{01CD}-\x{01CE}\x{01E6}-\x{01E7}\x{01EA}-\x{01EB}\x{01F4}-\x{01F5}\x{01FC}-\x{01FF}\x{0218}-\x{021B}\x{0232}-\x{0233}\x{0237}\x{0259}\x{02B9}-\x{02BA}\x{02BC}\x{02C6}-\x{02C7}\x{02C9}\x{02DA}\x{02DC}-\x{02DD}\x{02F3}\x{02F7}\x{0300}-\x{0301}\x{0303}-\x{0304}\x{0308}-\x{0309}\x{0323}\x{1E80}-\x{1E85}\x{1E9E}\x{1EF2}-\x{1EF9}\x{2013}-\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}\x{2022}\x{2026}\x{2032}-\x{2033}\x{2039}-\x{203A}\x{2044}\x{20AB}-\x{20AC}\x{20AE}\x{20BD}\x{20BF}\x{2113}\x{2122}\x{2191}\x{2193}\x{2212}\x{2215}]+/u';

    /**
     * Control, bidi and zero-width characters out, whitespace (newlines included)
     * collapsed (shared with the 30311 texts); then what the fonts cannot show.
     */
    public static function clean(string $s): string
    {
        $s = preg_replace(self::COVERED, '', PublicName::clean($s)) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /**
     * The cleaned string, cut to `$max` code points with a trailing "…".
     */
    public static function fit(string $s, int $max): string
    {
        $s = self::clean($s);

        return mb_strlen($s) <= $max ? $s : rtrim(mb_substr($s, 0, $max - 1)).'…';
    }

    /**
     * Remaining time as m:ss; from 1 h up (a daily game's clock) h:mm, minutes rounded down.
     */
    public static function clock(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        // From 1 h up the seconds are noise (a daily game's clock): h:mm, minutes rounded down.
        return $s >= 3600
            ? sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60))
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * Squares and pieces of one board; white at the bottom.
     *
     * @param  array{from: string, to: string}|null  $lastMove
     * @return array{squares: list<array{x: float, y: float, fill: string}>, pieces: list<array{id: string, x: float, y: float}>, sq: float}
     */
    public static function board(string $fen, ?array $lastMove, float $x, float $y, float $sq): array
    {
        $last = $lastMove ? [strtolower($lastMove['from']), strtolower($lastMove['to'])] : [];
        $squares = [];
        $pieces = [];
        foreach (explode('/', explode(' ', trim($fen))[0]) as $r => $row) {
            $f = 0;
            foreach (str_split($row) as $ch) {
                if (ctype_digit($ch)) {
                    $f += (int) $ch;

                    continue;
                }
                if ($f < 8 && $r < 8 && stripos('kqrbnp', $ch) !== false) {
                    $pieces[] = ['id' => (ctype_upper($ch) ? 'w' : 'b').strtolower($ch), 'x' => $x + $f * $sq, 'y' => $y + $r * $sq];
                }
                $f++;
            }
        }
        for ($r = 0; $r < 8; $r++) {
            for ($f = 0; $f < 8; $f++) {
                $light = ($r + $f) % 2 === 0;
                $squares[] = [
                    'x' => $x + $f * $sq, 'y' => $y + $r * $sq,
                    'fill' => in_array('abcdefgh'[$f].(8 - $r), $last, true) ? ($light ? '#F4C47F' : '#B8741F') : ($light ? '#CFCFD4' : '#62626C'),
                ];
            }
        }

        return ['squares' => $squares, 'pieces' => $pieces, 'sq' => $sq];
    }

    /**
     * A clock in fixed digit cells, laid out from $x0 (start) or ending at $x0 (end). The size
     * is the largest <= $maxSize at which the widest clock there is (four digits and a colon,
     * "59:59" or "24:00": 4.125 em) fits $maxWidth, so it does not change with the time shown.
     *
     * @return array{digits: list<array{ch: string, x: float}>, size: float, width: float}
     */
    public static function clockCells(int $ms, float $x0, float $maxSize, float $maxWidth, bool $alignEnd = false): array
    {
        $text = self::clock($ms);
        $em = array_sum(array_map(self::clockCellEm(...), str_split($text)));
        $size = floor(min($maxSize, $maxWidth / 4.125));
        $width = $em * $size;
        $cx = $alignEnd ? $x0 - $width : $x0;
        $digits = [];
        foreach (str_split($text) as $ch) {
            $w = ($ch === ':' ? 0.375 : 0.9375) * $size;
            $digits[] = ['ch' => $ch, 'x' => round($cx + $w / 2, 1)];
            $cx += $w;
        }

        return ['digits' => $digits, 'size' => $size, 'width' => $width];
    }

    /**
     * Width of one clock character in em: a digit cell or the narrower colon.
     */
    public static function clockCellEm(string $ch): float
    {
        return $ch === ':' ? 0.375 : 0.9375;
    }

    /**
     * Width of one character of the big single-layout clock in px.
     */
    public static function bigClockCellWidth(string $ch): int
    {
        return $ch === ':' ? 30 : 75;
    }

    /**
     * Whether a gallery card is a game still running (no result yet).
     *
     * @param  array<string, mixed>  $game
     */
    public static function isRunning(array $game): bool
    {
        return ($game['result'] ?? null) === null;
    }
}
