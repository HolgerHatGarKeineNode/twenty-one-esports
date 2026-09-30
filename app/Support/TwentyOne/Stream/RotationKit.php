<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\CasualCups;
use InvalidArgumentException;
use Normalizer;

/**
 * Shared layout helpers for the rotation scenes (resources/views/stream/rotation/*): text that fits without
 * a browser, clocks in fixed cells, board geometry from a FEN, and stats that may be missing.
 *
 * Fonts are the stream's hermetic pair (resources/fonts/stream): Unbounded 800 and JetBrains Mono 700
 * (latin + latin-ext). rsvg-convert reports no widths, so widths are computed here:
 * - JetBrains Mono: every glyph advances exactly 0.6 em.
 * - Unbounded: the advance table below (units per em 1000, read from Unbounded-800.ttf with fontTools for every
 *   code point the mono files also cover). Kerning is ignored; the fit keeps a 4 % reserve for it, and the
 *   pixel sonde of the scenes checks the result on the PNG.
 *
 * A name with a character Unbounded lacks (e.g. "Ł") would mix two faces, so such a name is set in
 * JetBrains Mono instead ({@see nameFont()}).
 */
final class RotationKit
{
    public const MONO = 'JetBrains Mono';

    public const DISPLAY = 'Unbounded';

    /** Code points the two JetBrains Mono files cover; anything else would render as a missing-glyph box. */
    private const COVERED = '/[^\x{0020}-\x{007E}\x{00A0}-\x{0131}\x{0134}-\x{017F}\x{018F}\x{0192}\x{01A0}-\x{01A1}\x{01AF}-\x{01B0}\x{01CD}-\x{01CE}\x{01E6}-\x{01E7}\x{01EA}-\x{01EB}\x{01F4}-\x{01F5}\x{01FC}-\x{01FF}\x{0218}-\x{021B}\x{0232}-\x{0233}\x{0237}\x{0259}\x{02B9}-\x{02BA}\x{02BC}\x{02C6}-\x{02C7}\x{02C9}\x{02DA}\x{02DC}-\x{02DD}\x{02F3}\x{02F7}\x{0300}-\x{0301}\x{0303}-\x{0304}\x{0308}-\x{0309}\x{0323}\x{1E80}-\x{1E85}\x{1E9E}\x{1EF2}-\x{1EF9}\x{2013}-\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}\x{2022}\x{2026}\x{2032}-\x{2033}\x{2039}-\x{203A}\x{2044}\x{20AB}-\x{20AC}\x{20AE}\x{20BD}\x{20BF}\x{2113}\x{2122}\x{2191}\x{2193}\x{2212}\x{2215}]+/u';

    /** @var array<int, int> Unbounded 800 advance widths (1/1000 em) by code point. */
    private const UNBOUNDED = [32 => 242, 33 => 338, 34 => 534, 35 => 867, 36 => 827, 37 => 1321, 38 => 961, 39 => 291, 40 => 410, 41 => 410, 42 => 542, 43 => 620, 44 => 316, 45 => 377, 46 => 314, 47 => 472, 48 => 935, 49 => 536, 50 => 850, 51 => 850, 52 => 880, 53 => 817, 54 => 867, 55 => 769, 56 => 905, 57 => 867, 58 => 339, 59 => 340, 60 => 620, 61 => 620, 62 => 620, 63 => 722, 64 => 1056, 65 => 977, 66 => 895, 67 => 942, 68 => 936, 69 => 803, 70 => 781, 71 => 970, 72 => 947, 73 => 362, 74 => 815, 75 => 882, 76 => 772, 77 => 1255, 78 => 983, 79 => 970, 80 => 825, 81 => 971, 82 => 863, 83 => 857, 84 => 829, 85 => 884, 86 => 951, 87 => 1375, 88 => 896, 89 => 940, 90 => 819, 91 => 451, 92 => 472, 93 => 451, 94 => 620, 95 => 482, 96 => 393, 97 => 839, 98 => 824, 99 => 774, 100 => 842, 101 => 742, 102 => 606, 103 => 825, 104 => 793, 105 => 334, 106 => 334, 107 => 763, 108 => 334, 109 => 1226, 110 => 805, 111 => 801, 112 => 824, 113 => 824, 114 => 569, 115 => 767, 116 => 619, 117 => 792, 118 => 788, 119 => 1154, 120 => 743, 121 => 797, 122 => 708, 123 => 493, 124 => 322, 125 => 493, 126 => 620, 160 => 242, 161 => 329, 162 => 799, 163 => 829, 164 => 740, 165 => 895, 166 => 322, 167 => 901, 168 => 625, 169 => 898, 170 => 658, 171 => 769, 172 => 620, 173 => 0, 174 => 802, 175 => 534, 176 => 487, 177 => 620, 178 => 545, 179 => 591, 180 => 393, 182 => 829, 183 => 320, 184 => 440, 185 => 462, 186 => 616, 187 => 769, 188 => 1160, 189 => 1191, 190 => 1268, 191 => 718, 192 => 977, 193 => 977, 194 => 977, 195 => 977, 196 => 977, 197 => 977, 198 => 1262, 199 => 942, 200 => 803, 201 => 803, 202 => 803, 203 => 803, 204 => 362, 205 => 362, 206 => 362, 207 => 362, 208 => 959, 209 => 983, 210 => 970, 211 => 970, 212 => 970, 213 => 970, 214 => 970, 215 => 620, 216 => 970, 217 => 884, 218 => 884, 219 => 884, 220 => 884, 221 => 940, 222 => 842, 223 => 918, 224 => 839, 225 => 839, 226 => 839, 227 => 839, 228 => 839, 229 => 839, 230 => 1267, 231 => 774, 232 => 742, 233 => 742, 234 => 742, 235 => 742, 236 => 334, 237 => 334, 238 => 334, 239 => 334, 240 => 796, 241 => 805, 242 => 801, 243 => 801, 244 => 801, 245 => 801, 246 => 801, 247 => 620, 248 => 801, 249 => 792, 250 => 792, 251 => 792, 252 => 792, 253 => 797, 254 => 824, 255 => 797, 258 => 977, 305 => 334, 338 => 1330, 339 => 1245, 700 => 310, 710 => 564, 730 => 445, 732 => 558, 768 => 0, 769 => 0, 771 => 0, 772 => 0, 776 => 0, 777 => 0, 803 => 0, 8211 => 665, 8212 => 1216, 8216 => 306, 8217 => 310, 8218 => 313, 8220 => 591, 8221 => 595, 8222 => 598, 8226 => 400, 8230 => 902, 8249 => 452, 8250 => 452, 8260 => 602, 8364 => 878, 8482 => 1082, 8593 => 784, 8595 => 784, 8722 => 620, 8725 => 602];

    /**
     * PublicName::clean() (control, bidi, zero-width out; whitespace collapsed), then only what the fonts show.
     */
    public static function clean(?string $s): string
    {
        $s = PublicName::clean((string) $s);
        // Styled letters (𝕞, 𝐁, ｆｕｌｌｗｉｄｔｈ) fold to the letters the fonts have, instead of being dropped.
        $folded = Normalizer::normalize($s, Normalizer::FORM_KC);
        $s = preg_replace(self::COVERED, '', is_string($folded) ? $folded : $s) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** Unbounded when it has every character of the (cleaned) string, JetBrains Mono otherwise. */
    public static function nameFont(string $s): string
    {
        foreach (mb_str_split($s) as $ch) {
            if (! isset(self::UNBOUNDED[mb_ord($ch)])) {
                return self::MONO;
            }
        }

        return self::DISPLAY;
    }

    /** Width in px of $s set in $font at $size. */
    public static function width(string $s, string $font, float $size): float
    {
        if ($font === self::MONO) {
            return mb_strlen($s) * 0.6 * $size;
        }

        $units = 0;
        foreach (mb_str_split($s) as $ch) {
            $units += self::UNBOUNDED[mb_ord($ch)] ?? 600;
        }

        return $units * $size / 1000;
    }

    /**
     * $s cleaned and shortened to fit $maxPx: longer strings keep as many characters as fit plus "…".
     * Unbounded keeps a 4 % reserve for kerning.
     */
    public static function fit(?string $s, string $font, float $size, float $maxPx): string
    {
        $s = self::clean($s);
        $reserve = $font === self::MONO ? 1.0 : 1.04;
        if (self::width($s, $font, $size) * $reserve <= $maxPx) {
            return $s;
        }
        $chars = mb_str_split($s);
        while ($chars !== [] && self::width(rtrim(implode('', $chars)).'…', $font, $size) * $reserve > $maxPx) {
            array_pop($chars);
        }

        return $chars === [] ? '' : rtrim(implode('', $chars)).'…';
    }

    /**
     * A player's name for a scene: text, face and the fallback when nothing printable is left.
     *
     * @return array{text: string, font: string}
     */
    public static function name(?string $name, string $fallback, float $size, float $maxPx, bool $display = true): array
    {
        $clean = self::clean($name);
        $font = $display && $clean !== '' ? self::nameFont($clean) : self::MONO;
        $text = self::fit($clean, $font, $size, $maxPx);

        return $text === '' ? ['text' => $fallback, 'font' => $display ? self::DISPLAY : self::MONO] : ['text' => $text, 'font' => $font];
    }

    /** m:ss below 1 h, h:mm from 1 h (a daily game's day per move), never negative. */
    public static function clockText(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        return $s >= 3600
            ? sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60))
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * A clock in fixed Unbounded digit cells (0.9375 em a digit, 0.375 em a colon), laid out from $x0 or ending at
     * $x0. The size is the largest <= $maxSize at which the widest clock (4.125 em: "59:59", "24:00") fits
     * $maxWidth, so it never changes with the time shown.
     *
     * @return array{digits: list<array{ch: string, x: float}>, size: float, width: float, x0: float, x1: float}
     */
    public static function clock(int $ms, float $x0, float $maxSize, float $maxWidth, bool $alignEnd = false): array
    {
        $text = self::clockText($ms);
        $size = floor(min($maxSize, $maxWidth / 4.125));
        $width = array_sum(array_map(fn (string $ch): float => $ch === ':' ? 0.375 : 0.9375, str_split($text))) * $size;
        $cx = $alignEnd ? $x0 - $width : $x0;
        $start = $cx;
        $digits = [];
        foreach (str_split($text) as $ch) {
            $w = ($ch === ':' ? 0.375 : 0.9375) * $size;
            $digits[] = ['ch' => $ch, 'x' => round($cx + $w / 2, 1)];
            $cx += $w;
        }

        return ['digits' => $digits, 'size' => $size, 'width' => $width, 'x0' => $start, 'x1' => $cx];
    }

    /**
     * The game's mode for a scene, in sentence case: "LIVE · CHESS BLITZ 5+3 · CASUAL" -> "Blitz 5+3, casual".
     * A result (after the game) wins over the mode.
     *
     * @param  array<string, mixed>  $game
     */
    public static function modeLabel(array $game): string
    {
        $result = self::clean($game['result'] ?? '');
        if ($result !== '') {
            return $result;
        }
        $mode = preg_replace('/^LIVE · (CHESS )?/u', '', self::clean($game['mode'] ?? '')) ?? '';
        $parts = array_map(fn (string $p): string => mb_strtolower(trim($p)), explode('·', $mode));
        $label = implode(', ', array_filter($parts, fn (string $p): bool => $p !== ''));

        return $label === '' ? 'Chess, casual' : mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    /**
     * True for a daily (correspondence) game: its clock is the time left for one move.
     *
     * @param  array<string, mixed>  $game
     */
    public static function isDaily(array $game): bool
    {
        return (bool) preg_match('/CORRESPONDENCE|DAILY/i', (string) ($game['mode'] ?? ''));
    }

    /**
     * The last moves as numbered rows: [number, white SAN|null, black SAN|null], numbered from the FEN's move
     * number and side to move. Without a usable list: [].
     *
     * @param  array<mixed>|null  $moves  the last up to 10 SAN moves, oldest first (anything else is dropped)
     * @return list<array{0: int, 1: ?string, 2: ?string}>
     */
    public static function moveRows(?array $moves, ?string $fen, int $limit = 10): array
    {
        $moves = array_values(array_filter(array_map(fn ($m): string => is_string($m) ? self::clean($m) : '', (array) $moves), fn (string $m): bool => $m !== '' && mb_strlen($m) <= 8));
        $moves = array_slice($moves, -$limit);
        $fields = explode(' ', trim((string) $fen));
        $number = max(1, (int) ($fields[5] ?? 1));
        $whiteToMove = ($fields[1] ?? 'w') === 'w';
        if ($moves === [] || count($fields) < 6) {
            return [];
        }
        // Ply index (0 = white's first move) of the move after the last one played, then of the first listed.
        $next = ($number - 1) * 2 + ($whiteToMove ? 0 : 1);
        $ply = max(0, $next - count($moves));
        $rows = [];
        foreach ($moves as $san) {
            $n = intdiv($ply, 2) + 1;
            $col = $ply % 2 === 0 ? 1 : 2;
            if ($rows === [] || end($rows)[0] !== $n) {
                $rows[] = [$n, null, null];
            }
            $i = count($rows) - 1;
            $rows[$i] = $col === 1 ? [$rows[$i][0], $san, $rows[$i][2]] : [$rows[$i][0], $rows[$i][1], $san];
            $ply++;
        }

        return $rows;
    }

    /** Board colours of the site's chess settings (light, dark). */
    public const THEMES = [
        'house' => ['#CFCFD4', '#62626C'],
        'wood' => ['#E4CCA2', '#8E5F3B'],
        'slate' => ['#DCE3EA', '#5E7891'],
        'orange' => ['#F4D9B0', '#B9640A'],
    ];

    /**
     * Squares and pieces of one board at ($x, $y) with square size $sq; white at the bottom.
     *
     * @param  array{from?: mixed, to?: mixed}|null  $lastMove
     * @return array{x: float, y: float, sq: float, size: float, squares: list<array{x: float, y: float, fill: string}>, pieces: list<array{id: string, x: float, y: float}>}
     */
    public static function board(?string $fen, ?array $lastMove, float $x, float $y, float $sq, string $theme = 'house'): array
    {
        [$light, $dark] = self::THEMES[$theme] ?? self::THEMES['house'];
        $last = $lastMove ? [strtolower((string) ($lastMove['from'] ?? '')), strtolower((string) ($lastMove['to'] ?? ''))] : [];
        $pieces = [];
        foreach (array_slice(explode('/', explode(' ', trim((string) $fen))[0]), 0, 8) as $r => $row) {
            $f = 0;
            foreach (str_split($row) as $ch) {
                if (ctype_digit($ch)) {
                    $f += (int) $ch;

                    continue;
                }
                if ($f < 8 && stripos('kqrbnp', $ch) !== false) {
                    $pieces[] = ['id' => (ctype_upper($ch) ? 'w' : 'b').strtolower($ch), 'x' => $x + $f * $sq, 'y' => $y + $r * $sq];
                }
                $f++;
            }
        }
        $squares = [];
        for ($r = 0; $r < 8; $r++) {
            for ($f = 0; $f < 8; $f++) {
                $isLight = ($r + $f) % 2 === 0;
                $squares[] = [
                    'x' => $x + $f * $sq, 'y' => $y + $r * $sq,
                    'fill' => in_array('abcdefgh'[$f].(8 - $r), $last, true) ? ($isLight ? '#F4C47F' : '#B8741F') : ($isLight ? $light : $dark),
                ];
            }
        }

        return ['x' => $x, 'y' => $y, 'sq' => $sq, 'size' => 8 * $sq, 'squares' => $squares, 'pieces' => $pieces];
    }

    /**
     * A count from the stats array, or null when it is missing or not a whole number >= 0.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function count(array $stats, string $key): ?int
    {
        $v = $stats[$key] ?? null;

        return is_int($v) && $v >= 0 ? $v : null;
    }

    /** "1 clan" / "3 clans"; null stays null. */
    public static function plural(?int $n, string $one, string $many): ?string
    {
        return $n === null ? null : $n.' '.($n === 1 ? $one : $many);
    }

    /**
     * Ladder rows that are complete enough to show (a name and an Elo), at most $limit.
     *
     * Accepts StreamStats' keys (wins, draws, losses) and the short ones (w, d, l).
     *
     * @param  array<string, mixed>  $stats
     * @return list<array{name: string, elo: int, games: ?int, w: ?int, d: ?int, l: ?int}>
     */
    public static function ladder(array $stats, string $key, int $limit): array
    {
        $rows = [];
        foreach (array_slice((array) ($stats['ladders'][$key] ?? []), 0, $limit) as $row) {
            if (! is_array($row) || ! is_int($row['elo'] ?? null) || self::clean($row['name'] ?? '') === '') {
                continue;
            }
            $rows[] = [
                'name' => (string) $row['name'], 'elo' => $row['elo'], 'games' => self::whole($row, 'games'),
                'w' => self::whole($row, 'wins') ?? self::whole($row, 'w'),
                'd' => self::whole($row, 'draws') ?? self::whole($row, 'd'),
                'l' => self::whole($row, 'losses') ?? self::whole($row, 'l'),
            ];
        }

        return $rows;
    }

    /**
     * A whole number >= 0 under $key, or null.
     *
     * @param  array<string, mixed>  $row
     */
    private static function whole(array $row, string $key): ?int
    {
        $v = $row[$key] ?? null;

        return is_int($v) && $v >= 0 ? $v : null;
    }

    /**
     * Direction B's ticker: the counts that are known, as text, then the site URL. Mono 20 (12 px a character),
     * 56 px between items (24 + an 8 px square + 24); counts are dropped from the end until everything fits 1200 px.
     *
     * @param  array<string, mixed>  $stats
     * @return list<string>
     */
    public static function tickerItems(array $stats): array
    {
        $items = [];
        foreach ([['players', 'player', 'players'], ['clans', 'clan', 'clans'], ['gamesPlayed', 'game played', 'games played'], ['gamesToday', 'game today', 'games today']] as [$key, $one, $many]) {
            $text = self::plural(self::count($stats, $key), $one, $many);
            if ($text !== null) {
                $items[] = $text;
            }
        }
        $url = 'esports.einundzwanzig.space';
        while ($items !== []) {
            $width = mb_strlen($url) * 12;
            foreach ($items as $item) {
                $width += mb_strlen($item) * 12 + 56;
            }
            if ($width <= 1200) {
                break;
            }
            array_pop($items);
        }
        $items[] = $url;

        return $items;
    }

    /**
     * Direction C's stats bar: [label, value] for every known count.
     *
     * @param  array<string, mixed>  $stats
     * @return list<array{0: string, 1: int}>
     */
    public static function statCells(array $stats): array
    {
        $cells = [];
        foreach ([['players', 'players'], ['clans', 'clans'], ['gamesPlayed', 'games played'], ['liveNow', 'live now'], ['gamesToday', 'games today']] as [$key, $label]) {
            $value = self::count($stats, $key);
            if ($value !== null) {
                $cells[] = [$label, $value];
            }
        }

        return $cells;
    }

    /**
     * "1/0/0" or null when any part is missing.
     *
     * @param  array{w: ?int, d: ?int, l: ?int}  $row
     */
    public static function record(array $row): ?string
    {
        return $row['w'] === null || $row['d'] === null || $row['l'] === null ? null : $row['w'].'/'.$row['d'].'/'.$row['l'];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Tournament slides (resources/views/stream/rotation/t{a,b,c}{1,2}-*.blade.php). $t is the $tournament array of
    // the plan's data contract; every reader below tolerates a missing or mistyped key.
    // ---------------------------------------------------------------------------------------------------------------

    /** Longest cover data URI accepted (bytes); a 480x270 JPEG is about 43 kB as base64. */
    private const COVER_MAX = 400_000;

    /**
     * The cover as an inline JPEG/PNG data URI, or null. Anything else (a URL, a file:// path, markup) is refused:
     * rsvg-convert would read a local file behind an href while rendering.
     */
    public static function coverUri(mixed $uri): ?string
    {
        if (! is_string($uri) || strlen($uri) > self::COVER_MAX) {
            return null;
        }

        return preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$#', $uri) === 1 ? $uri : null;
    }

    /**
     * $s cleaned and broken into at most $maxLines lines of at most $maxPx. What does not fit ends the last line with
     * "…" after the last whole word that fits (a single word longer than a line is cut inside the word).
     *
     * @return list<string>
     */
    public static function wrap(?string $s, string $font, float $size, float $maxPx, int $maxLines): array
    {
        $words = explode(' ', self::clean($s));
        if ($words === [''] || $maxLines < 1) {
            return [];
        }
        $reserve = $font === self::MONO ? 1.0 : 1.04;
        $lines = [];
        $line = '';
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $try = $line === '' ? $words[$i] : $line.' '.$words[$i];
            if (self::width($try, $font, $size) * $reserve <= $maxPx) {
                $line = $try;

                continue;
            }
            if (count($lines) === $maxLines - 1) {
                $lines[] = self::lastLine($line, array_slice($words, $i), $font, $size, $maxPx);

                return $lines;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = self::fit($words[$i], $font, $size, $maxPx);
            if ($line !== $words[$i]) {
                // The word alone is too long for a line: it was cut, so nothing after it can follow on this line.
                $lines[] = $line;
                if (count($lines) === $maxLines || $i === $count - 1) {
                    return $lines;
                }
                $line = '';
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The last line of wrap(): $line plus as many of $rest as fit with "…" after them.
     *
     * @param  list<string>  $rest
     */
    private static function lastLine(string $line, array $rest, string $font, float $size, float $maxPx): string
    {
        $reserve = $font === self::MONO ? 1.0 : 1.04;
        foreach ($rest as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if (self::width($try.'…', $font, $size) * $reserve > $maxPx) {
                break;
            }
            $line = $try;
        }
        if ($line === '') {
            return self::fit(implode(' ', $rest), $font, $size, $maxPx);
        }
        while (self::width($line.'…', $font, $size) * $reserve > $maxPx && str_contains($line, ' ')) {
            $line = substr($line, 0, (int) strrpos($line, ' '));
        }

        return self::width($line.'…', $font, $size) * $reserve > $maxPx ? self::fit($line.' x', $font, $size, $maxPx) : rtrim($line, ' ,.;:').'…';
    }

    /**
     * A headline in at most $maxLines lines: the first size of $sizes (largest first) at which it fits whole, else
     * the last size, shortened with "…". Unbounded when it has every character, JetBrains Mono otherwise.
     *
     * @param  list<float|int>  $sizes
     * @return array{lines: list<string>, size: float, font: string}
     */
    public static function headline(?string $s, array $sizes, float $maxPx, int $maxLines, ?string $font = null): array
    {
        $clean = self::clean($s);
        $font ??= $clean === '' ? self::DISPLAY : self::nameFont($clean);
        $lines = [];
        $size = 0.0;
        foreach ($sizes as $size) {
            $size = (float) $size;
            $lines = self::wrap($clean, $font, $size, $maxPx, $maxLines);
            if (implode(' ', $lines) === $clean) {
                break;
            }
        }

        return ['lines' => $lines, 'size' => $size, 'font' => $font];
    }

    /**
     * The countdown string of the data contract ("6d 06:05:01", "06:05:01", "42:10") as parts, or null when it does
     * not parse. Hours of 24 and more are carried into days.
     *
     * @return array{days: int, h: int, m: int, s: int, hms: string}|null
     */
    public static function countdownParts(mixed $text): ?array
    {
        if (! is_string($text) || ! preg_match('/^\s*(?:(\d{1,4})\s*d(?:ays?)?\s*)?(?:(\d{1,3}):)?(\d{1,2}):(\d{2})\s*$/', $text, $m)) {
            return null;
        }
        $days = (int) $m[1];
        $h = $m[2] === '' ? 0 : (int) $m[2];
        $days += intdiv($h, 24);
        $h %= 24;
        $min = (int) $m[3];
        $sec = (int) $m[4];
        if ($min > 59 || $sec > 59) {
            return null;
        }

        return ['days' => $days, 'h' => $h, 'm' => $min, 's' => $sec, 'hms' => sprintf('%02d:%02d:%02d', $h, $min, $sec)];
    }

    /**
     * The line above the big hh:mm:ss: "6 days" / "1 day", and on the last day the urgency the audience should
     * feel ("Last day to sign up", "Last hour to sign up"). A full tournament gets no urgency line.
     *
     * @param  array{days: int, h: int, m: int, s: int, hms: string}|null  $parts
     */
    public static function countdownNote(?array $parts, bool $full): ?string
    {
        return match (true) {
            $parts === null => null,
            $parts['days'] > 0 => $parts['days'].' '.($parts['days'] === 1 ? 'day' : 'days'),
            $full, $parts['hms'] === '00:00:00' => null,
            $parts['h'] > 0 => 'Last day to sign up',
            default => 'Last hour to sign up',
        };
    }

    /**
     * Text in fixed Unbounded cells (0.9375 em a digit, 0.375 em a colon, other characters their own advance), from
     * $x0 or ending at $x0. The width depends only on the shape of the text, never on the digits, so a countdown that
     * ticks every second does not jitter. Same shape as clock(), for partials/clock.
     *
     * @return array{digits: list<array{ch: string, x: float}>, size: float, width: float, x0: float, x1: float}
     */
    public static function digitCells(string $text, float $x0, float $size, bool $alignEnd = false): array
    {
        $cells = [];
        $width = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $w = (ctype_digit($ch) ? 0.9375 : ($ch === ':' ? 0.375 : (self::UNBOUNDED[mb_ord($ch)] ?? 600) / 1000)) * $size;
            $cells[] = [$ch, $w];
            $width += $w;
        }
        $cx = $alignEnd ? $x0 - $width : $x0;
        $start = $cx;
        $digits = [];
        foreach ($cells as [$ch, $w]) {
            $digits[] = ['ch' => $ch, 'x' => round($cx + $w / 2, 1)];
            $cx += $w;
        }

        return ['digits' => $digits, 'size' => $size, 'width' => $width, 'x0' => $start, 'x1' => $cx];
    }

    /**
     * Places taken and left, with the site's wording.
     *
     * @param  array<string, mixed>  $t
     * @return array{taken: int, places: int, left: int, full: bool, takenText: string, leftText: string}
     */
    public static function spots(array $t): array
    {
        $places = is_int($t['places'] ?? null) && $t['places'] > 0 ? $t['places'] : 1;
        $taken = is_int($t['taken'] ?? null) ? max(0, min($places, $t['taken'])) : 0;
        $left = is_int($t['spotsLeft'] ?? null) ? max(0, min($places, $t['spotsLeft'])) : $places - $taken;

        return [
            'taken' => $taken, 'places' => $places, 'left' => $left, 'full' => $left === 0,
            'takenText' => $taken.' of '.$places.' spots taken',
            'leftText' => $left === 0 ? 'Sign-up is full' : $left.' '.($left === 1 ? 'spot left' : 'spots left'),
        ];
    }

    /**
     * The site's spots bar: one cell per place up to $max places, beyond that one bar filled in proportion.
     *
     * @return list<array{x: float, w: float, filled: bool}>
     */
    public static function spotCells(int $taken, int $places, float $x, float $w, float $gap, int $max = 32): array
    {
        $places = max(1, $places);
        $taken = max(0, min($places, $taken));
        if ($places > $max) {
            $filled = round($w * $taken / $places, 1);

            $bar = [];
            if ($filled >= 1) {
                $bar[] = ['x' => $x, 'w' => $filled, 'filled' => true];
            }
            if ($w - $filled >= 1) {
                $bar[] = ['x' => $x + $filled, 'w' => $w - $filled, 'filled' => false];
            }

            return $bar;
        }
        $cw = ($w - $gap * ($places - 1)) / $places;
        $cells = [];
        for ($i = 0; $i < $places; $i++) {
            $cells[] = ['x' => round($x + $i * ($cw + $gap), 1), 'w' => round($cw, 1), 'filled' => $i < $taken];
        }

        return $cells;
    }

    /**
     * "EA Sports FC 26, 1v1, Two Stage": the parts that are set, cleaned.
     *
     * @param  array<string, mixed>  $t
     */
    public static function tournamentMeta(array $t): string
    {
        $parts = [];
        foreach (['game', 'mode', 'format'] as $key) {
            $v = self::clean(is_string($t[$key] ?? null) ? $t[$key] : '');
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * "Rated" only when the contract says so; anything else is "Casual".
     *
     * @param  array<string, mixed>  $t
     */
    public static function ratedLabel(array $t): string
    {
        return ($t['rated'] ?? false) === true ? 'Rated' : 'Casual';
    }

    /**
     * Where it is played, "Online" when missing.
     *
     * @param  array<string, mixed>  $t
     */
    public static function where(array $t): string
    {
        $where = self::clean(is_string($t['where'] ?? null) ? $t['where'] : '');

        return $where === '' ? 'Online' : $where;
    }

    /**
     * A plain string field of the contract, cleaned, or $fallback.
     *
     * @param  array<string, mixed>  $t
     */
    public static function text(array $t, string $key, string $fallback = ''): string
    {
        $v = self::clean(is_string($t[$key] ?? null) ? $t[$key] : '');

        return $v === '' ? $fallback : $v;
    }

    /**
     * The tournament's address as text, without a scheme; only host/path characters, else the site's address.
     *
     * @param  array<string, mixed>  $t
     */
    public static function tournamentUrl(array $t): string
    {
        $url = preg_replace('#^https?://#i', '', self::text($t, 'url')) ?? '';

        return preg_match('#^[A-Za-z0-9.-]+(/[A-Za-z0-9._/-]*)?$#', $url) === 1 && strlen($url) <= 80 ? $url : 'esports.einundzwanzig.space';
    }

    /** The largest whole JetBrains Mono size <= $maxSize at which $s fits $maxPx (never below 12). */
    public static function monoSize(string $s, float $maxSize, float $maxPx): float
    {
        $len = max(1, mb_strlen($s));

        return max(12.0, min($maxSize, floor($maxPx / ($len * 0.6))));
    }

    /**
     * The seeding list: rows with a printable name, at most $limit, and how many more there are.
     *
     * @param  array<string, mixed>  $t
     * @return array{rows: list<array{seed: int, name: string, rating: ?int}>, more: int, open: int}
     */
    public static function roster(array $t, int $limit): array
    {
        $rows = [];
        foreach (array_values(is_array($t['roster'] ?? null) ? $t['roster'] : []) as $i => $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || self::clean($row['name']) === '') {
                continue;
            }
            $rows[] = [
                'seed' => is_int($row['seed'] ?? null) && $row['seed'] > 0 ? $row['seed'] : $i + 1,
                'name' => $row['name'],
                'rating' => is_int($row['rating'] ?? null) ? $row['rating'] : null,
            ];
        }
        $open = is_int($t['openSpots'] ?? null) ? max(0, $t['openSpots']) : self::spots($t)['left'];

        return ['rows' => array_slice($rows, 0, $limit), 'more' => max(0, count($rows) - $limit), 'open' => $open];
    }

    /**
     * The projected groups (kind 'groups') or first-round pairings (kind 'bracket') laid out as boxes in a grid of
     * $cols columns inside ($x, $y, $w, $h). Row pitch is the largest <= $maxPitch at which every box fits; when even
     * $minPitch does not fit, whole rows of boxes are dropped (counted in 'hidden'), and when one box alone is too
     * tall its last rows fold into "+N more". Boxes are as tall as their content, not stretched.
     *
     * A group title is "Group A" (string keys of one to three characters are used as given, others count A, B, …).
     * A row with name null is an open spot. Coordinates: 'titleY' and each row's 'y' are text baselines.
     *
     * @param  array<string, mixed>|null  $preview
     * @return array{kind: ?string, boxes: list<array{x: float, y: float, w: float, h: float, title: ?string, titleY: float, rows: list<array{seed: ?int, name: ?string, y: float}>, more: int, moreY: float}>, pitch: float, size: float, hidden: int, byes: list<int>, stageNote: string, pad: float}
     */
    public static function previewBoxes(?array $preview, float $x, float $y, float $w, float $h, int $cols, float $gap, float $titleH, float $maxPitch, float $minPitch = 26, float $pad = 12): array
    {
        $kind = in_array($preview['kind'] ?? null, ['groups', 'bracket'], true) ? $preview['kind'] : null;
        $items = [];
        if ($kind === 'groups') {
            $i = 0;
            foreach ((is_array($preview['groups'] ?? null) ? $preview['groups'] : []) as $key => $members) {
                $label = is_string($key) && ! is_numeric($key) && mb_strlen(self::clean($key)) >= 1 && mb_strlen(self::clean($key)) <= 3
                    ? self::clean($key) : ($i < 26 ? chr(65 + $i) : (string) ($i + 1));
                $items[] = ['title' => 'Group '.$label, 'rows' => self::slots(is_array($members) ? $members : [])];
                $i++;
            }
        } elseif ($kind === 'bracket') {
            foreach ((is_array($preview['matches'] ?? null) ? $preview['matches'] : []) as $match) {
                $sides = is_array($match) && is_array($match['sides'] ?? null) ? array_slice($match['sides'], 0, 2) : [];
                $items[] = ['title' => null, 'rows' => self::slots($sides)];
            }
        }
        $kept = [];
        foreach ($items as $item) {
            if ($item['rows'] !== []) {
                $kept[] = $item;
            }
        }
        $items = $kept;
        $byes = [];
        foreach ((is_array($preview['byes'] ?? null) ? $preview['byes'] : []) as $bye) {
            if (is_int($bye)) {
                $byes[] = $bye;
            }
        }
        $out = ['kind' => $kind, 'boxes' => [], 'pitch' => $maxPitch, 'size' => 0.0, 'hidden' => 0, 'byes' => $byes,
            'stageNote' => self::clean(is_string($preview['stageNote'] ?? null) ? $preview['stageNote'] : ''), 'pad' => $pad];
        if ($items === []) {
            return $out;
        }
        $tH = $kind === 'groups' ? $titleH : 0.0;
        // Pairings: one wide column when every pairing fits at a pitch of $minPitch + 4 (names get the whole width).
        if ($kind === 'bracket' && count($items) * (2 * ($minPitch + 4) + 2 * $pad) + (count($items) - 1) * $gap <= $h) {
            $cols = 1;
        }
        $maxRows = 1;
        foreach ($items as $item) {
            $maxRows = max($maxRows, count($item['rows']));
        }
        $cols = max(1, $cols);
        $n = count($items);
        $pitch = $maxPitch;
        while (true) {
            $gridRows = (int) ceil($n / $cols);
            $boxH = ($h - $gap * ($gridRows - 1)) / $gridRows;
            $pitch = min($maxPitch, ($boxH - $tH - 2 * $pad) / $maxRows);
            if ($pitch >= $minPitch || $n <= $cols) {
                break;
            }
            $n = max($cols, $n - $cols);
        }
        $shownRows = $maxRows;
        if ($pitch < $minPitch) {
            $pitch = $minPitch;
            $shownRows = max(1, (int) floor(($boxH - $tH - 2 * $pad) / $minPitch));
        }
        $bw = ($w - $gap * ($cols - 1)) / $cols;
        $contentRows = min($maxRows, $shownRows);
        $bh = $tH + 2 * $pad + $contentRows * $pitch;
        $boxes = [];
        foreach (array_slice($items, 0, $n) as $i => $item) {
            $bx = round($x + ($i % $cols) * ($bw + $gap), 1);
            $by = round($y + intdiv($i, $cols) * ($bh + $gap), 1);
            $fold = count($item['rows']) > $shownRows;
            $keep = $fold ? $shownRows - 1 : count($item['rows']);
            $rows = [];
            foreach (array_slice($item['rows'], 0, $keep) as $j => $row) {
                $rows[] = $row + ['y' => round($by + $pad + $tH + $j * $pitch + $pitch * 0.68, 1)];
            }
            $boxes[] = [
                'x' => $bx, 'y' => $by, 'w' => round($bw, 1), 'h' => round($bh, 1),
                'title' => $item['title'], 'titleY' => round($by + $pad + $tH * 0.62, 1), 'rows' => $rows,
                'more' => $fold ? count($item['rows']) - $keep : 0,
                'moreY' => round($by + $pad + $tH + $keep * $pitch + $pitch * 0.68, 1),
            ];
        }

        return ['boxes' => $boxes, 'pitch' => round($pitch, 1), 'size' => floor(min(20, $pitch * 0.56)), 'hidden' => count($items) - $n] + $out;
    }

    /**
     * Seats of a group or a match: seed int|null, name string|null (null or unprintable = open spot).
     *
     * @param  array<mixed>  $seats
     * @return list<array{seed: ?int, name: ?string}>
     */
    private static function slots(array $seats): array
    {
        $rows = [];
        foreach ($seats as $seat) {
            if (! is_array($seat)) {
                continue;
            }
            $name = is_string($seat['name'] ?? null) && self::clean($seat['name']) !== '' ? $seat['name'] : null;
            $rows[] = ['seed' => is_int($seat['seed'] ?? null) ? $seat['seed'] : null, 'name' => $name];
        }

        return $rows;
    }

    /**
     * The heading of the preview: the contract's stageNote ("Groups if sign-up closed now", "Round 1 if sign-up closed
     * now"), else the same wording derived from the kind.
     *
     * @param  array{kind: ?string, stageNote: string}  $p
     */
    public static function previewHeading(array $p): string
    {
        if ($p['stageNote'] !== '') {
            return $p['stageNote'];
        }

        return $p['kind'] === 'bracket' ? 'Round 1 if sign-up closed now' : 'Groups if sign-up closed now';
    }

    /**
     * The line under the preview: the byes and how many groups or matches did not fit ('' when neither).
     *
     * @param  array{kind: ?string, hidden: int, byes: list<int>}  $p
     */
    public static function previewFoot(array $p): string
    {
        $parts = [];
        if ($p['byes'] !== []) {
            $parts[] = (count($p['byes']) === 1 ? 'Bye for seed ' : 'Byes for seeds ').implode(', ', array_slice($p['byes'], 0, 8)).(count($p['byes']) > 8 ? ', …' : '');
        }
        if ($p['hidden'] > 0) {
            $parts[] = '+'.$p['hidden'].' more '.($p['kind'] === 'groups' ? ($p['hidden'] === 1 ? 'group' : 'groups') : ($p['hidden'] === 1 ? 'match' : 'matches'));
        }

        return $parts === [] ? '' : implode('. ', $parts).'.';
    }

    /**
     * The "Who plays" list as the site shows it, in $slots rows: the seeded players, then (while there is room) the
     * invitation "Your spot?" on the next seed, open seats as "Open spot", and "+N more open spots" for the rest.
     * With no free row left the invitation carries the count itself ('more' on the invite row).
     *
     * @param  array<string, mixed>  $t
     * @return list<array{kind: string, seed: ?int, name: ?string, rating: ?int, more: int}>
     */
    public static function whoPlays(array $t, int $slots): array
    {
        $roster = self::roster($t, $slots);
        $rows = [];
        $seed = 0;
        foreach ($roster['rows'] as $r) {
            $rows[] = ['kind' => 'player', 'seed' => $r['seed'], 'name' => $r['name'], 'rating' => $r['rating'], 'more' => 0];
            $seed = max($seed, $r['seed']);
        }
        $open = $roster['open'];
        if ($open === 0 || count($rows) >= $slots) {
            return $rows;
        }
        $rest = $open - 1;
        $free = $slots - count($rows) - 1;
        // No room after the invite row: it carries the count of the remaining open spots itself.
        $rows[] = ['kind' => 'invite', 'seed' => $seed + 1, 'name' => null, 'rating' => null, 'more' => $rest > 0 && $free === 0 ? $rest : 0];
        if ($rest > 0 && $free === 0) {
            return $rows;
        }
        $list = $rest <= $free ? $rest : $free - 1;
        for ($i = 1; $i <= $list; $i++) {
            $rows[] = ['kind' => 'open', 'seed' => $seed + 1 + $i, 'name' => null, 'rating' => null, 'more' => 0];
        }
        if ($rest > $list) {
            $rows[] = ['kind' => 'more', 'seed' => null, 'name' => null, 'rating' => null, 'more' => $rest - $list];
        }

        return $rows;
    }

    /** "+9 more open spots" / "+1 more open spot". */
    public static function moreOpen(int $n): string
    {
        return '+'.$n.' more open '.($n === 1 ? 'spot' : 'spots');
    }

    /**
     * The live viewer badge of a scene: an eye, the count ("12,345", thousands separated) and the word "watching",
     * right-aligned to $right on the baseline $y. Null when the count is unknown (anything but an int): the scene then
     * renders exactly as it does without a badge. 0 is a real count and shows as "0 watching".
     *
     * The word is set with text-anchor="end" at $right, the count ends one mono space before it, so the right edge
     * is exact; only the eye's position depends on the count's width (Unbounded: the advance table + 4 % reserve).
     * The eye is a 24-unit drawing (almond outline, filled pupil) scaled to the count's cap height.
     *
     * @return array{count: string, countX: float, wordX: float, eyeX: float, eyeY: float, eyeScale: float, box: string, x0: float, y: float, countFont: string, countSize: float, wordSize: float}|null
     */
    public static function viewerBadge(mixed $viewers, float $right, float $y, string $countFont, float $countSize, float $wordSize): ?array
    {
        if (! is_int($viewers)) {
            return null;
        }
        $count = number_format(max(0, $viewers));
        $countW = self::width($count, $countFont, $countSize) * ($countFont === self::MONO ? 1.0 : 1.04);
        $countX = $right - self::width('watching', self::MONO, $wordSize) - 0.6 * $wordSize;
        $cap = $countSize * ($countFont === self::MONO ? 0.73 : 0.75);
        $eyeScale = round($cap / 13, 3);
        $eyeX = $countX - $countW - round(0.5 * $countSize) - 24 * $eyeScale;
        $top = $y - max($cap, 0.73 * $wordSize);

        return [
            'count' => $count, 'countX' => round($countX, 1), 'wordX' => $right,
            'eyeX' => round($eyeX, 1), 'eyeY' => round($y - $cap / 2 - 12 * $eyeScale, 1), 'eyeScale' => $eyeScale,
            'box' => floor($eyeX - 2).' '.floor($top - 3).' '.ceil($right + 2).' '.ceil($y + 0.25 * $wordSize + 2),
            'x0' => floor($eyeX), 'y' => $y, 'countFont' => $countFont, 'countSize' => $countSize, 'wordSize' => $wordSize,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Faces and backdrops (docs/plans/2026-09-27T1811-stream-avatars-imagery.md): every person carries 'avatar', a clan
    // entry 'logo', every scene 'backdrop', each an inline data URI or null. Only what the readers below accept reaches
    // an <image>; rsvg-convert never gets a URL or a path it could follow.
    // ---------------------------------------------------------------------------------------------------------------

    /** Longest avatar or clan logo data URI accepted (bytes); a 128x128 JPEG is 5 to 10 kB as base64. */
    private const AVATAR_MAX = 96_000;

    /**
     * An avatar or clan logo as an inline JPEG, PNG or SVG data URI, or null (the view then draws a neutral circle).
     * An SVG must be self-contained: no reference but "#fragment", no DTD, no script, no stylesheet import.
     */
    public static function avatarUri(mixed $uri): ?string
    {
        if (! is_string($uri) || strlen($uri) > self::AVATAR_MAX
            || preg_match('#^data:image/(jpeg|png|svg\+xml);base64,([A-Za-z0-9+/]+={0,2})$#', $uri, $m) !== 1) {
            return null;
        }
        if ($m[1] !== 'svg+xml') {
            return $uri;
        }
        $svg = base64_decode($m[2], true);

        return $svg === false || preg_match('/<!DOCTYPE|<!ENTITY|<script|<foreignObject|<\?xml-stylesheet|@import|(?:href|src)\s*=\s*["\']\s*(?!#)|url\(\s*["\']?\s*(?!#)/i', $svg) === 1
            ? null : $uri;
    }

    /** The scene's backdrop (already blurred and darkened) as an inline JPEG/PNG data URI, or null: as coverUri(). */
    public static function backdropUri(mixed $uri): ?string
    {
        return self::coverUri($uri);
    }

    /**
     * The avatar of each row ladder() keeps, in the same order (null where there is none).
     *
     * @param  array<string, mixed>  $stats
     * @return list<?string>
     */
    public static function ladderAvatars(array $stats, string $key, int $limit): array
    {
        $out = [];
        foreach (array_slice((array) ($stats['ladders'][$key] ?? []), 0, $limit) as $row) {
            if (! is_array($row) || ! is_int($row['elo'] ?? null) || self::clean($row['name'] ?? '') === '') {
                continue;
            }
            $out[] = self::avatarUri($row['avatar'] ?? null);
        }

        return $out;
    }

    /**
     * What stands next to a name: the seat's avatar (a player), else its logo (a clan lineup), else, in a team
     * tournament ($clan), a tile with the clan's tag for a named seat. All null: a neutral placeholder. A seat carries
     * both keys: a player has avatar set and logo null, a lineup avatar null and logo set or null.
     *
     * A logo is drawn whole ('fit' meet: a crest with alpha must not lose its corners), a face fills its circle.
     *
     * @return array{uri: ?string, tag: ?string, fit: string}
     */
    public static function face(mixed $seat, bool $clan = false): array
    {
        if (! is_array($seat)) {
            return ['uri' => null, 'tag' => null, 'fit' => 'slice'];
        }
        $avatar = self::avatarUri($seat['avatar'] ?? null);
        $uri = $avatar ?? self::avatarUri($seat['logo'] ?? null);
        $name = is_string($seat['name'] ?? null) ? $seat['name'] : '';

        return [
            'uri' => $uri,
            'tag' => $uri === null && $clan && self::clean($name) !== '' ? self::clanTag(is_string($seat['tag'] ?? null) ? $seat['tag'] : '', $name) : null,
            'fit' => $avatar === null && $uri !== null ? 'meet' : 'slice',
        ];
    }

    /** A clan tile's text: its tag (at most 4 characters, also from a "[TAG] Name"), else the name's first letter. */
    public static function clanTag(string $tag, string $name): string
    {
        $tag = mb_strtoupper(self::clean($tag));
        if ($tag === '' && preg_match('/^\[([^\]]{1,4})\]/u', self::clean($name), $m) === 1) {
            $tag = mb_strtoupper($m[1]);
        }

        return $tag !== '' && mb_strlen($tag) <= 4 ? $tag : mb_strtoupper(mb_substr(self::clean($name), 0, 1));
    }

    /**
     * face() of every row roster() keeps (all of them, before its limit), in the same order; whoPlays()' player rows are
     * the first of these. A team tournament (teamSize > 1) seats clans.
     *
     * @param  array<string, mixed>  $t
     * @return list<array{uri: ?string, tag: ?string, fit: string}>
     */
    public static function rosterFaces(array $t): array
    {
        $clan = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
        $out = [];
        foreach (array_values(is_array($t['roster'] ?? null) ? $t['roster'] : []) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || self::clean($row['name']) === '') {
                continue;
            }
            $out[] = self::face($row, $clan);
        }

        return $out;
    }

    /**
     * rosterFaces() spread over the places each row takes (its `seats`: a lineup's team size, a player one; 1 when
     * missing), in roster order, then one seat per `solos` entry (a team tournament's unseeded solo sign-up) with its
     * avatar: the face of every taken seat of seats(). The roster is cut after its first rows, so with solos present
     * the seats of seeded rows it left out keep a neutral face, and each solo stays on its own seat.
     *
     * @param  array<string, mixed>  $t
     * @return list<array{uri: ?string, tag: ?string, fit: string}>
     */
    public static function seatFaces(array $t): array
    {
        $clan = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
        $out = [];
        foreach (array_values(is_array($t['roster'] ?? null) ? $t['roster'] : []) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || self::clean($row['name']) === '') {
                continue;
            }
            $face = self::face($row, $clan);
            $seats = is_int($row['seats'] ?? null) ? max(1, min(32, $row['seats'])) : 1;
            for ($i = 0; $i < $seats; $i++) {
                $out[] = $face;
            }
        }
        $solos = array_values(array_filter(is_array($t['solos'] ?? null) ? $t['solos'] : [], is_array(...)));
        if ($solos === []) {
            return $out;
        }
        $seeded = self::spots($t)['taken'] - count($solos);
        $out = array_slice($out, 0, max(0, $seeded));
        while (count($out) < $seeded) {
            $out[] = self::face(null);
        }
        foreach ($solos as $solo) {
            $out[] = self::face($solo);
        }

        return $out;
    }

    /**
     * face() of every seat of previewBoxes(), per box and row in the same order (boxes that previewBoxes() drops for
     * room are at the end, so the indexes match).
     *
     * @param  array<string, mixed>|null  $preview
     * @return list<list<array{uri: ?string, tag: ?string, fit: string}>>
     */
    public static function previewFaces(?array $preview, bool $clan = false): array
    {
        $lists = [];
        if (($preview['kind'] ?? null) === 'groups') {
            foreach ((is_array($preview['groups'] ?? null) ? $preview['groups'] : []) as $members) {
                $lists[] = is_array($members) ? $members : [];
            }
        } elseif (($preview['kind'] ?? null) === 'bracket') {
            foreach ((is_array($preview['matches'] ?? null) ? $preview['matches'] : []) as $match) {
                $lists[] = is_array($match) && is_array($match['sides'] ?? null) ? array_slice($match['sides'], 0, 2) : [];
            }
        }
        $out = [];
        foreach ($lists as $seats) {
            $faces = [];
            foreach ($seats as $seat) {
                if (is_array($seat)) {
                    $faces[] = self::face($seat, $clan);
                }
            }
            if ($faces !== []) {
                $out[] = $faces;
            }
        }

        return $out;
    }

    /**
     * The spots as a seat map: one circle per place, at most $perRow in a row, the taken ones first and filled, the
     * first of those with the roster's faces, each as often as its row takes places (seatFaces()). Null for more than
     * $max places (the caller keeps the proportional bar).
     *
     * @param  array<string, mixed>  $t
     * @return array{d: float, h: float, seats: list<array{x: float, y: float, filled: bool, face: array{uri: ?string, tag: ?string, fit: string}|null}>}|null
     */
    public static function seats(array $t, float $x, float $y, float $w, float $maxD, int $perRow = 16, int $max = 32, float $gap = 6): ?array
    {
        $spots = self::spots($t);
        if ($spots['places'] > $max) {
            return null;
        }
        $faces = self::seatFaces($t);
        $cols = min($spots['places'], max(1, $perRow));
        $d = floor(min($maxD, ($w - $gap * ($cols - 1)) / $cols));
        $seats = [];
        for ($i = 0; $i < $spots['places']; $i++) {
            $filled = $i < $spots['taken'];
            $seats[] = [
                'x' => round($x + ($i % $cols) * ($d + $gap), 1), 'y' => round($y + intdiv($i, $cols) * ($d + $gap), 1),
                'filled' => $filled, 'face' => $filled ? ($faces[$i] ?? null) : null,
            ];
        }

        return ['d' => $d, 'h' => ceil($spots['places'] / $cols) * ($d + $gap) - $gap, 'seats' => $seats];
    }
    // Feature slides (d1 prize pots, d2 casual cups): stream views define no closures, so their data work is here.

    /** Sats as the slides print them: "21,000". */
    public static function sats(int $sats): string
    {
        return number_format($sats, 0, '.', ',');
    }

    /**
     * The upcoming tournaments that carry a pot (TournamentSlides' `pot`), biggest first, and their sum.
     *
     * @param  list<array<string, mixed>>  $upcoming
     * @return array{total: int, count: int, rows: list<array{name: string, line: string, sats: int, starts: string}>}
     */
    public static function pots(array $upcoming, int $limit): array
    {
        $rows = [];
        $total = 0;

        foreach ($upcoming as $t) {
            if (! is_int($t['pot'] ?? null) || $t['pot'] <= 0) {
                continue;
            }

            $total += $t['pot'];
            $rows[] = ['name' => self::text($t, 'name'), 'line' => self::text($t, 'game'), 'sats' => $t['pot'], 'starts' => self::text($t, 'startsAt')];
        }

        usort($rows, static fn (array $a, array $b): int => $b['sats'] <=> $a['sats']);

        return ['total' => $total, 'count' => count($rows), 'rows' => array_slice($rows, 0, $limit)];
    }

    /**
     * The open casual cups (TournamentSlides' `cup`), one entry per game in the order given (soonest close
     * first), each with its regions' cups (EU and US, user 2026-09-28), at most `$regions`, by region. The cover is
     * the game's `coverTile` (288x162, drawn 1:1) where it was built, else the full `cover`.
     *
     * @param  list<array<string, mixed>>  $upcoming
     * @return list<array{game: string, cover: string|null, regions: list<array{region: string, taken: int, places: int, closes: string}>}>
     */
    public static function cups(array $upcoming, int $limit, int $regions = 2): array
    {
        $cups = [];

        foreach ($upcoming as $t) {
            if (($t['cup'] ?? false) !== true) {
                continue;
            }

            $game = self::text($t, 'game');
            $region = self::text($t, 'region');

            if ($game === '' || isset($cups[$game]['regions'][$region])) {
                continue;
            }

            $cups[$game]['game'] = $game;
            $cups[$game]['cover'] ??= self::coverUri($t['coverTile'] ?? null) ?? self::coverUri($t['cover'] ?? null);
            $cups[$game]['regions'][$region] = [
                'region' => $region,
                'taken' => is_int($t['taken'] ?? null) ? $t['taken'] : 0,
                'places' => is_int($t['places'] ?? null) ? $t['places'] : 0,
                'closes' => self::text($t, 'signupClosesAt'),
            ];
        }

        return array_slice(array_values(array_map(function (array $cup) use ($regions): array {
            ksort($cup['regions']);
            $cup['regions'] = array_slice(array_values($cup['regions']), 0, max(1, $regions));

            return $cup;
        }, $cups)), 0, $limit);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Every game's ladders and the pride slides (a3, b3, c3, b4, d2, d3, e1-e9, plan "Stream-Slides: alle Spiele,
    // Stolz-Momente"): the readers tolerate a missing or mistyped key, as the tournament readers above.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * StreamStats' `boards` that can be drawn: a game name and at least one row with a name and an Elo. Every row
     * keeps name, elo, games, w/d/l, avatar (a data URI, a lineup row its clan's logo) and tag (a lineup row's clan tag).
     *
     * @param  array<string, mixed>  $stats
     * @return list<array{game: string, mode: string, title: string, gameName: string, modeName: string, rated: bool, rows: list<array{rank: int, name: string, elo: int, games: ?int, w: ?int, d: ?int, l: ?int, avatar: ?string, tag: ?string}>}>
     */
    public static function ladderBoards(array $stats): array
    {
        $boards = [];

        foreach (is_array($stats['boards'] ?? null) ? $stats['boards'] : [] as $board) {
            if (! is_array($board) || self::text($board, 'gameName') === '') {
                continue;
            }

            $rows = [];
            foreach (is_array($board['rows'] ?? null) ? $board['rows'] : [] as $row) {
                if (! is_array($row) || ! is_int($row['elo'] ?? null) || self::clean($row['name'] ?? '') === '') {
                    continue;
                }
                $rows[] = [
                    'rank' => count($rows) + 1, 'name' => (string) $row['name'], 'elo' => $row['elo'], 'games' => self::whole($row, 'games'),
                    'w' => self::whole($row, 'wins'), 'd' => self::whole($row, 'draws'), 'l' => self::whole($row, 'losses'),
                    'avatar' => self::avatarUri($row['avatar'] ?? null),
                    'tag' => is_string($row['tag'] ?? null) ? $row['tag'] : null,
                ];
            }

            if ($rows === []) {
                continue;
            }

            $gameName = self::text($board, 'gameName');
            $modeName = self::text($board, 'modeName');
            $boards[] = [
                'game' => self::text($board, 'game'), 'mode' => self::text($board, 'mode'),
                'title' => $modeName === '' ? $gameName : $gameName.' '.$modeName, 'gameName' => $gameName, 'modeName' => $modeName,
                'rated' => ($board['pool'] ?? null) === 'rated', 'rows' => $rows,
            ];
        }

        return $boards;
    }

    /**
     * Two ladders for a3's split field, taking turns: the boards in pairs, pair `$turn` modulo their number. One
     * board alone comes with nothing beside it; without boards the list is empty.
     *
     * @param  array<string, mixed>  $stats
     * @return list<array<string, mixed>>
     */
    public static function ladderPair(array $stats, int $turn): array
    {
        $pairs = array_chunk(self::ladderBoards($stats), 2);

        return $pairs === [] ? [] : $pairs[max(0, $turn) % count($pairs)];
    }

    /**
     * The correspondence ladders of ladderBoards() (chess daily, a board game's correspondence), leaders only.
     *
     * @param  array<string, mixed>  $stats
     * @return list<array{game: string, title: string, row: array<string, mixed>}>
     */
    public static function dailyLeaders(array $stats, int $limit): array
    {
        $out = [];

        foreach (self::ladderBoards($stats) as $board) {
            if ($board['mode'] === 'correspondence') {
                $out[] = ['game' => $board['gameName'], 'title' => $board['gameName'], 'row' => $board['rows'][0]];
            }
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Up to `$limit` names joined for a line: "Chess", "Chess and Checkers", "Chess, Checkers and Rocket League".
     *
     * @param  mixed  $names  a list of strings; anything else counts as none
     */
    public static function listing(mixed $names, int $limit = 3): string
    {
        $clean = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $name = is_string($name) ? self::clean($name) : '';
            if ($name !== '' && ! in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }
        $clean = array_slice($clean, 0, max(1, $limit));
        $last = array_pop($clean);

        return $last === null ? '' : ($clean === [] ? $last : implode(', ', $clean).' and '.$last);
    }

    /** "1st", "2nd", "3rd", "4th" … "11th", "12th", "13th", "21st". */
    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /**
     * A pride row's face: its `avatar` checked (avatarUri), else a neutral placeholder.
     *
     * @return array{uri: ?string, tag: ?string, fit: string}
     */
    public static function prideFace(mixed $row): array
    {
        return ['uri' => is_array($row) ? self::avatarUri($row['avatar'] ?? null) : null, 'tag' => null, 'fit' => 'slice'];
    }

    /**
     * The rows of a pride list that have a name, at most `$limit`.
     *
     * @return list<array<string, mixed>>
     */
    public static function prideRows(mixed $rows, int $limit): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && self::clean(is_string($row['name'] ?? null) ? $row['name'] : '') !== '') {
                $out[] = $row;
            }
        }

        return array_slice($out, 0, max(0, $limit));
    }

    /**
     * The chain strip of e5: the heights up to `$height` (at most `$count`, never below 1), oldest first.
     *
     * @return list<int>
     */
    public static function chainHeights(int $height, int $count): array
    {
        return $height < 1 ? [] : range(max(1, $height - $count + 1), $height);
    }

    /**
     * A hex colour (#RRGGBB) or the fallback: a tier colour from the data never reaches an attribute unchecked.
     */
    public static function colour(mixed $hex, string $fallback): string
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? $hex : $fallback;
    }

    /**
     * The casual cup slots (one per game, the same local time in each region; user, 2026-09-30) in two lines: the days
     * the enabled games' cups start on, "Fridays to Sundays, a slot per game", and the regions' clocks, "Local time: EU
     * Berlin, US New York". A game without a valid slot is left out; without any the first line is left out.
     *
     * @return list<string>
     */
    public static function cupSlots(): array
    {
        $days = [];

        foreach (CasualCups::enabledGames() as $game) {
            try {
                $days[] = array_search(CasualCups::slotOf($game)['weekday'], LeagueSettings::WEEKDAYS, true);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $slots = [];
        $days = array_values(array_filter($days, is_int(...)));

        if ($days !== []) {
            [$first, $last] = [ucfirst(LeagueSettings::WEEKDAYS[min($days)]).'s', ucfirst(LeagueSettings::WEEKDAYS[max($days)]).'s'];
            $slots[] = ($first === $last ? $first : $first.' to '.$last).', a slot per game';
        }

        $clocks = array_map(function (array $region): string {
            $city = str_replace('_', ' ', (string) (explode('/', $region['timezone'])[1] ?? $region['timezone']));

            return self::clean($region['label']).' '.$city;
        }, array_values(CasualCups::regions()));

        if ($clocks !== []) {
            $slots[] = 'Local time: '.implode(', ', $clocks);
        }

        return $slots;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Tournaments past sign-up (TournamentLiveSlides; views t{a,b,c}{3..7}): the live bracket, tables and groups laid out
    // without a browser, and the lines the pride and next-tournament slides print. Every reader tolerates a missing or
    // mistyped key: the scene test renders every view with a sign-up frame too.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * A bracket board (kind 'bracket') laid out in ($x, $y, $w, $h): one column per round, a header line of $headH on
     * top, every match a box of two rows. The row pitch is the largest <= $maxPitch at which the fullest column fits
     * (never below $minPitch). The first column stacks from the top; a later box sits at the middle of the boxes that
     * feed it (by `from`, else by position), never overlapping the one above. `links` are the lines from each shown
     * feeder to its box (elbows in the gap between columns), `live` when the box they lead to is live.
     *
     * @param  array<string, mixed>|null  $board
     * @return array{columns: list<array{x: float, w: float, label: string, current: bool, note: string, boxes: list<array{x: float, y: float, w: float, h: float, state: string, rows: list<array{name: string, known: bool, score: ?string, won: bool, face: array{uri: ?string, tag: ?string, fit: string}, y: float, top: float}>}>}>, links: list<array{d: string, live: bool}>, pitch: float, size: float, fd: float, pad: float, top: float}
     */
    public static function bracketLayout(?array $board, float $x, float $y, float $w, float $h, float $headH = 30, float $maxPitch = 40, float $minPitch = 28, float $gapX = 32, bool $clan = false): array
    {
        $out = ['columns' => [], 'links' => [], 'pitch' => $maxPitch, 'size' => 16.0, 'fd' => 0.0, 'pad' => 4.0, 'top' => $y];
        $columns = ($board['kind'] ?? null) === 'bracket' && is_array($board['columns'] ?? null) ? array_values(array_filter($board['columns'], is_array(...))) : [];
        $columns = array_values(array_filter($columns, fn (array $c): bool => is_array($c['matches'] ?? null) && $c['matches'] !== []));

        if ($columns === []) {
            return $out;
        }

        $pad = 4.0;
        $gap = 10.0;
        $most = max(array_map(fn (array $c): int => count($c['matches']), $columns));
        $pitch = floor(min($maxPitch, max($minPitch, ($h - $headH - ($most - 1) * $gap - $most * 2 * $pad) / (2 * $most))));
        $boxH = 2 * $pitch + 2 * $pad;
        $colW = ($w - (count($columns) - 1) * $gapX) / count($columns);
        $fd = floor($pitch - 6);
        $size = floor(min(18, $pitch * 0.56));
        $prev = [];

        foreach ($columns as $ci => $column) {
            $cx = round($x + $ci * ($colW + $gapX), 1);
            $boxes = [];
            $count = count($column['matches']);
            $lastBottom = -INF;

            foreach (array_values($column['matches']) as $mi => $match) {
                $feeders = [];

                if ($ci > 0) {
                    foreach ((array) ($match['from'] ?? []) as $key) {
                        if (is_string($key) && isset($prev[$key])) {
                            $feeders[] = $prev[$key];
                        }
                    }

                    if ($feeders === [] && $prev !== []) {
                        // By position: the boxes of the previous column that share this box's share of it.
                        $keys = array_keys($prev);
                        $per = count($keys) / $count;
                        foreach (array_slice($keys, (int) floor($mi * $per), max(1, (int) round($per))) as $key) {
                            $feeders[] = $prev[$key];
                        }
                    }
                }

                $center = $feeders === [] ? $y + $headH + $mi * ($boxH + $gap) + $boxH / 2 : array_sum(array_column($feeders, 'cy')) / count($feeders);
                $top = max($center - $boxH / 2, $lastBottom + $gap, $y + $headH);
                $top = min($top, $y + $h - $boxH);
                $lastBottom = $top + $boxH;
                $rows = [];

                foreach (array_slice(array_values(is_array($match['sides'] ?? null) ? $match['sides'] : []), 0, 2) as $si => $side) {
                    $side = is_array($side) ? $side : [];
                    $rowTop = $top + $pad + $si * $pitch;
                    $rows[] = [
                        'name' => self::clean(is_string($side['name'] ?? null) ? $side['name'] : ''),
                        'known' => ($side['known'] ?? false) === true,
                        'score' => is_string($side['score'] ?? null) ? self::clean($side['score']) : null,
                        'won' => ($side['won'] ?? false) === true,
                        'face' => self::face($side, $clan),
                        'top' => round($rowTop + ($pitch - $fd) / 2, 1),
                        'y' => round($rowTop + $pitch / 2 + $size * 0.36, 1),
                    ];
                }

                // 'void': a voided match (FairPlay) keeps its place, dimmed and marked, never as one still to play.
                $state = in_array($match['state'] ?? null, ['live', 'done', 'waiting', 'bye', 'void'], true) ? $match['state'] : 'waiting';
                $boxes[] = ['x' => $cx, 'y' => round($top, 1), 'w' => round($colW, 1), 'h' => $boxH, 'state' => $state, 'rows' => $rows];

                foreach ($feeders as $feeder) {
                    $x1 = $feeder['x'] + $feeder['w'];
                    $xm = round($x1 + $gapX / 2, 1);
                    $out['links'][] = ['d' => 'M'.round($x1, 1).' '.round($feeder['cy'], 1).'H'.$xm.'V'.round($top + $boxH / 2, 1).'H'.$cx, 'live' => $state === 'live'];
                }
            }

            $prev = [];
            foreach (array_values($column['matches']) as $mi => $match) {
                $key = is_string($match['key'] ?? null) ? $match['key'] : 'm'.$ci.'-'.$mi;
                $prev[$key] = ['x' => $boxes[$mi]['x'], 'w' => $boxes[$mi]['w'], 'cy' => $boxes[$mi]['y'] + $boxH / 2];
            }

            $total = is_int($column['total'] ?? null) ? $column['total'] : $count;
            $from = is_int($column['from'] ?? null) ? $column['from'] : 0;
            $out['columns'][] = [
                'x' => $cx, 'w' => round($colW, 1),
                'label' => self::clean(is_string($column['label'] ?? null) ? $column['label'] : ''),
                'current' => ($column['current'] ?? false) === true,
                // A cropped round says which part of it shows.
                'note' => $total > $count ? 'Matches '.($from + 1).'-'.($from + $count).' of '.$total : '',
                'boxes' => $boxes,
            ];
        }

        return ['columns' => $out['columns'], 'links' => $out['links'], 'pitch' => $pitch, 'size' => $size, 'fd' => $fd, 'pad' => $pad, 'top' => $y];
    }

    /**
     * A groups board (kind 'groups') as boxes in a grid of $cols columns: a title line and one row per table row
     * (rank, face, name, points), `through` marking the places that go on.
     *
     * @param  array<string, mixed>|null  $board
     * @return array{boxes: list<array{x: float, y: float, w: float, h: float, title: string, titleY: float, rows: list<array{rank: int, name: string, points: string, through: bool, face: array{uri: ?string, tag: ?string, fit: string}, y: float, top: float}>}>, pitch: float, size: float, fd: float, hidden: int}
     */
    public static function groupsLayout(?array $board, float $x, float $y, float $w, float $h, int $cols, float $gap = 16, float $titleH = 30, float $maxPitch = 36, float $minPitch = 24, bool $clan = false): array
    {
        $groups = ($board['kind'] ?? null) === 'groups' && is_array($board['groups'] ?? null) ? array_values(array_filter($board['groups'], is_array(...))) : [];
        $out = ['boxes' => [], 'pitch' => $maxPitch, 'size' => 16.0, 'fd' => 0.0, 'hidden' => 0];

        if ($groups === []) {
            return $out;
        }

        $cols = max(1, min($cols, count($groups)));
        $maxRows = max(1, ...array_map(fn (array $g): int => count(is_array($g['rows'] ?? null) ? $g['rows'] : []), $groups));
        $gridRows = (int) ceil(count($groups) / $cols);
        $pad = 10.0;
        $pitch = floor(min($maxPitch, ($h - ($gridRows - 1) * $gap - $gridRows * ($titleH + 2 * $pad)) / ($gridRows * $maxRows)));

        // Too many groups for the room: whole grid rows go, counted in `hidden`.
        while ($pitch < $minPitch && $gridRows > 1) {
            $gridRows--;
            $pitch = floor(min($maxPitch, ($h - ($gridRows - 1) * $gap - $gridRows * ($titleH + 2 * $pad)) / ($gridRows * $maxRows)));
        }

        $pitch = max($minPitch, $pitch);
        $shown = array_slice($groups, 0, $gridRows * $cols);
        $bw = ($w - ($cols - 1) * $gap) / $cols;
        $bh = $titleH + 2 * $pad + $maxRows * $pitch;
        $fd = floor($pitch - 8);
        $size = floor(min(18, $pitch * 0.56));

        foreach ($shown as $i => $group) {
            $bx = round($x + ($i % $cols) * ($bw + $gap), 1);
            $by = round($y + intdiv($i, $cols) * ($bh + $gap), 1);
            $rows = [];

            foreach (array_values(is_array($group['rows'] ?? null) ? $group['rows'] : []) as $ri => $row) {
                $row = is_array($row) ? $row : [];
                $rowTop = $by + $pad + $titleH + $ri * $pitch;
                $rows[] = [
                    'rank' => is_int($row['rank'] ?? null) ? $row['rank'] : $ri + 1,
                    'name' => self::clean(is_string($row['name'] ?? null) ? $row['name'] : ''),
                    'points' => self::clean(is_string($row['points'] ?? null) ? $row['points'] : ''),
                    'through' => ($row['through'] ?? false) === true,
                    'face' => self::face($row, $clan),
                    'top' => round($rowTop + ($pitch - $fd) / 2, 1),
                    'y' => round($rowTop + $pitch / 2 + $size * 0.36, 1),
                ];
            }

            $boxes[] = ['x' => $bx, 'y' => $by, 'w' => round($bw, 1), 'h' => round($bh, 1), 'title' => self::clean(is_string($group['title'] ?? null) ? $group['title'] : ''),
                'titleY' => round($by + $pad + $titleH * 0.62, 1), 'rows' => $rows];
        }

        return ['boxes' => $boxes ?? [], 'pitch' => $pitch, 'size' => $size, 'fd' => $fd, 'hidden' => count($groups) - count($shown)];
    }

    /**
     * The rows of a table board (kind 'table'): rank, face, name, record, points, at most $limit, each at $pitch from $y.
     *
     * @param  array<string, mixed>|null  $board
     * @return list<array{rank: int, name: string, points: string, record: string, through: bool, face: array{uri: ?string, tag: ?string, fit: string}, y: float, top: float}>
     */
    public static function tableRows(?array $board, float $y, float $pitch, float $fd, float $size, int $limit, bool $clan = false): array
    {
        $rows = [];

        foreach (array_slice(array_values(($board['kind'] ?? null) === 'table' && is_array($board['rows'] ?? null) ? $board['rows'] : []), 0, $limit) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $rowTop = $y + $i * $pitch;
            $rows[] = [
                'rank' => is_int($row['rank'] ?? null) ? $row['rank'] : $i + 1,
                'name' => self::clean(is_string($row['name'] ?? null) ? $row['name'] : ''),
                'points' => self::clean(is_string($row['points'] ?? null) ? $row['points'] : ''),
                'record' => self::clean(is_string($row['record'] ?? null) ? $row['record'] : ''),
                'through' => ($row['through'] ?? false) === true,
                'face' => self::face($row, $clan),
                'top' => round($rowTop + ($pitch - $fd) / 2, 1),
                'y' => round($rowTop + $pitch / 2 + $size * 0.36, 1),
            ];
        }

        return $rows;
    }

    /**
     * The pairings of a table board's current round (or a spotlight list), as two names and a score each:
     * "Hal 1-0 Adam", a live one "Hal vs Adam".
     *
     * @return list<array{a: string, b: string, score: string, live: bool, faces: list<array{uri: ?string, tag: ?string, fit: string}>}>
     */
    public static function pairings(mixed $matches, int $limit, bool $clan = false): array
    {
        $out = [];

        foreach (array_slice(array_values(is_array($matches) ? $matches : []), 0, $limit) as $match) {
            $sides = is_array($match['sides'] ?? null) ? array_values($match['sides']) : [];

            if (count($sides) < 2 || ! is_array($sides[0]) || ! is_array($sides[1])) {
                continue;
            }

            $a = self::clean(is_string($sides[0]['name'] ?? null) ? $sides[0]['name'] : '');
            $b = self::clean(is_string($sides[1]['name'] ?? null) ? $sides[1]['name'] : '');
            $sa = is_string($sides[0]['score'] ?? null) ? self::clean($sides[0]['score']) : '';
            $sb = is_string($sides[1]['score'] ?? null) ? self::clean($sides[1]['score']) : '';
            $state = $match['state'] ?? 'live';
            $out[] = ['a' => $a === '' ? 'Player' : $a, 'b' => $b === '' ? 'Player' : $b, 'score' => in_array($state, ['done', 'void'], true) ? ($sa !== '' && $sb !== '' ? $sa.'-'.$sb : '') : 'vs',
                'live' => $state === 'live' || ! isset($match['state']), 'faces' => [self::face($sides[0], $clan), self::face($sides[1], $clan)]];
        }

        return $out;
    }

    /** "The final two", "The final four", "The last eight", "12 still standing"; '' without a count. */
    public static function standingHeadline(mixed $count): string
    {
        return match (true) {
            ! is_int($count) || $count < 1 => '',
            $count === 1 => 'One left standing',
            $count === 2 => 'The final two',
            $count <= 4 => 'The final '.['', '', '', 'three', 'four'][$count],
            $count <= 8 => 'The last '.['', '', '', '', '', 'five', 'six', 'seven', 'eight'][$count],
            default => $count.' still standing',
        };
    }

    /**
     * "Made the top 8" for a field cut to 8, 4 or 2 (the places are then theirs at worst), '' otherwise. A field of 8
     * or fewer at the start has not made a cut yet.
     */
    public static function cutLine(mixed $count, mixed $of): string
    {
        if (! is_int($count) || ! is_int($of) || $count >= $of) {
            return '';
        }

        foreach ([2, 4, 8] as $cut) {
            if ($count <= $cut && $of > $cut) {
                return 'Made the top '.$cut;
            }
        }

        return '';
    }

    /**
     * A result as a line: "Hal beat Adam 2-1", a draw "Hal and Adam drew 1-1"; '' when the result is unreadable.
     *
     * @param  mixed  $result  TournamentLiveSlides result {winner, loser, label, draw}
     */
    public static function resultLine(mixed $result, string $font, float $size, float $maxPx): string
    {
        if (! is_array($result) || ! is_array($result['winner'] ?? null) || ! is_array($result['loser'] ?? null)) {
            return '';
        }

        $winner = self::clean(is_string($result['winner']['name'] ?? null) ? $result['winner']['name'] : '');
        $loser = self::clean(is_string($result['loser']['name'] ?? null) ? $result['loser']['name'] : '');

        if ($winner === '' || $loser === '') {
            return '';
        }

        // Decided without a game (TournamentLiveSlides `how`): "Hal advances by forfeit", never a played score.
        if (in_array($result['how'] ?? null, [' by forfeit', ', no-show', ' on seeding', ' by lot', ' on Armageddon'], true)) {
            $tail = ' advances'.$result['how'];

            return self::fit($winner, $font, $size, $maxPx - self::width($tail, $font, $size) * ($font === self::MONO ? 1.0 : 1.04)).$tail;
        }

        $score = is_string($result['label'] ?? null) ? ' '.str_replace('–', '-', self::clean($result['label'])) : '';
        $verb = ($result['draw'] ?? false) === true ? ' and ' : ' beat ';
        $tail = (($result['draw'] ?? false) === true ? ' drew' : '').$score;
        // Both names share what the line leaves them, the longer one gives first.
        $room = $maxPx - self::width($verb.$tail, $font, $size) * ($font === self::MONO ? 1.0 : 1.04);
        $half = $room / 2;
        $w = self::width($winner, $font, $size);
        $l = self::width($loser, $font, $size);
        $winnerRoom = $w + $l <= $room ? $w + 1 : max($half, $room - min($l, $half));

        return self::fit($winner, $font, $size, $winnerRoom).$verb.self::fit($loser, $font, $size, $room - min($w, $winnerRoom)).$tail;
    }

    /**
     * "Seed 7 beat seed 2" for an upset; '' without seeds.
     *
     * @param  mixed  $upset  TournamentLiveSlides upset
     */
    public static function upsetLine(mixed $upset): string
    {
        $w = is_array($upset) && is_array($upset['winner'] ?? null) ? ($upset['winner']['seed'] ?? null) : null;
        $l = is_array($upset) && is_array($upset['loser'] ?? null) ? ($upset['loser']['seed'] ?? null) : null;

        return is_int($w) && is_int($l) ? 'Seed '.$w.' beat seed '.$l : '';
    }

    /**
     * The facts of "how it runs" that are set, as [label, value] (Matches, Show up, Starts, Ends).
     *
     * @param  mixed  $how  TournamentPlaybook::of()
     * @return list<array{0: string, 1: string}>
     */
    public static function howFacts(mixed $how): array
    {
        $facts = [];

        foreach (['matches' => 'Matches', 'showUp' => 'Show up', 'starts' => 'Starts', 'ends' => 'Ends'] as $key => $label) {
            $value = is_array($how) && is_string($how[$key] ?? null) ? self::clean($how[$key]) : '';

            if ($value !== '') {
                $facts[] = [$label, $value];
            }
        }

        return $facts;
    }

    /**
     * The stages of "how it runs", numbered in play order.
     *
     * @param  mixed  $how  TournamentPlaybook::of()
     * @return list<array{n: int, title: string, line: string}>
     */
    public static function howSteps(mixed $how, int $limit = 3): array
    {
        $steps = [];

        foreach (array_slice(array_values(is_array($how) && is_array($how['steps'] ?? null) ? $how['steps'] : []), 0, $limit) as $step) {
            $title = is_array($step) && is_string($step['title'] ?? null) ? self::clean($step['title']) : '';
            $line = is_array($step) && is_string($step['line'] ?? null) ? self::clean($step['line']) : '';

            if ($title !== '' || $line !== '') {
                $steps[] = ['n' => count($steps) + 1, 'title' => $title, 'line' => $line];
            }
        }

        return $steps;
    }

    /**
     * The line that ties the next-tournament slide to the one on show: "Halving Cup is live." by phase. Only the name
     * is shortened to fit $maxPx, never the sentence; print it as it is (fitting it again would clean its "…").
     *
     * @param  array<string, mixed>  $t
     */
    public static function nextContext(array $t, string $font, float $size, float $maxPx): string
    {
        $tail = match ($t['phase'] ?? null) {
            'finished' => ' has its champion.',
            'drawing' => ' is about to start.',
            default => ' is live.',
        };
        $name = self::fit(self::text($t, 'name', 'This tournament'), $font, $size, $maxPx - self::width($tail, $font, $size) * ($font === self::MONO ? 1.0 : 1.04));

        return ($name === '' ? 'This tournament' : $name).$tail;
    }

    /**
     * face() of every entry of a list (standing, podium, field), in order, with its name and seed.
     *
     * @return list<array{name: string, seed: ?int, place: ?int, face: array{uri: ?string, tag: ?string, fit: string}}>
     */
    public static function entryFaces(mixed $entries, int $limit, bool $clan = false): array
    {
        $out = [];

        foreach (array_slice(array_values(is_array($entries) ? $entries : []), 0, $limit) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $out[] = [
                'name' => self::clean(is_string($entry['name'] ?? null) ? $entry['name'] : ''),
                'seed' => is_int($entry['seed'] ?? null) ? $entry['seed'] : null,
                'place' => is_int($entry['place'] ?? null) ? $entry['place'] : null,
                'face' => self::face($entry, $clan),
            ];
        }

        return $out;
    }

    /**
     * A tournament address in two lines for a narrow column: the host, then the path ("/tournaments/12").
     *
     * @param  array<string, mixed>  $t
     * @return array{0: string, 1: string}
     */
    public static function urlLines(array $t): array
    {
        $url = self::tournamentUrl($t);
        $slash = strpos($url, '/');

        return $slash === false ? [$url, ''] : [substr($url, 0, $slash), substr($url, $slash)];
    }

    /**
     * Who the pride slide (t?5) puts on show, with its headline and the line under it, by phase and board:
     * - finished: the podium ("They made it count"), each with its place;
     * - running, a bracket: who is still standing ("The last eight"), and "Made the top 8" once a cut is made;
     * - running, a table: its top rows with their points ("Top of the table");
     * - running, groups: who goes through as it stands, each with its group rank ("Through, as it stands").
     * Empty faces when there is nobody to show yet.
     *
     * @param  array<string, mixed>  $t
     * @return array{headline: string, sub: string, faces: list<array{name: string, seed: ?int, place: ?int, note: string, face: array{uri: ?string, tag: ?string, fit: string}}>, more: int}
     */
    public static function prideFaces(array $t, int $limit): array
    {
        $clan = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
        $board = is_array($t['board'] ?? null) ? $t['board'] : null;
        $kind = $board['kind'] ?? null;

        if (($t['phase'] ?? null) === 'finished') {
            $faces = array_map(fn (array $f): array => $f + ['note' => $f['place'] === null ? '' : self::ordinal($f['place'])], self::entryFaces($t['podium'] ?? [], $limit, $clan));

            return ['headline' => $faces === [] ? 'The results are in' : 'They made it count', 'sub' => self::text($t, 'name'), 'faces' => $faces, 'more' => 0];
        }

        if ($kind === 'table') {
            $faces = [];
            foreach (self::tableRows($board, 0, 1, 1, 1, $limit, $clan) as $row) {
                $faces[] = ['name' => $row['name'], 'seed' => null, 'place' => $row['rank'], 'note' => $row['points'].' '.($row['points'] === '1' ? 'point' : 'points'), 'face' => $row['face']];
            }

            return ['headline' => 'Top of the table', 'sub' => self::text($t, 'now'), 'faces' => $faces, 'more' => 0];
        }

        if ($kind === 'groups') {
            $faces = [];
            foreach ((array) ($board['groups'] ?? []) as $group) {
                foreach ((array) (is_array($group) ? ($group['rows'] ?? []) : []) as $row) {
                    if (is_array($row) && ($row['through'] ?? false) === true && count($faces) < $limit) {
                        $faces[] = ['name' => self::clean(is_string($row['name'] ?? null) ? $row['name'] : ''), 'seed' => null, 'place' => null,
                            'note' => self::clean(is_string($group['title'] ?? null) ? $group['title'] : '').', '.self::ordinal(is_int($row['rank'] ?? null) ? $row['rank'] : 1), 'face' => self::face($row, $clan)];
                    }
                }
            }

            return ['headline' => 'Through, as it stands', 'sub' => self::text($t, 'now'), 'faces' => $faces, 'more' => 0];
        }

        $standing = is_array($t['standing'] ?? null) ? $t['standing'] : [];
        $count = is_int($standing['count'] ?? null) ? $standing['count'] : null;
        $of = is_int($standing['of'] ?? null) ? $standing['of'] : null;
        $faces = array_map(fn (array $f): array => $f + ['note' => $f['seed'] === null ? '' : 'Seed '.$f['seed']], self::entryFaces($standing['faces'] ?? [], $limit, $clan));
        $cut = self::cutLine($count, $of);

        return [
            'headline' => self::standingHeadline($count),
            'sub' => $cut !== '' ? $cut : ($of !== null ? 'of '.$of.' who started' : ''),
            'faces' => $faces,
            'more' => $count === null ? 0 : max(0, $count - count($faces)),
        ];
    }
}
