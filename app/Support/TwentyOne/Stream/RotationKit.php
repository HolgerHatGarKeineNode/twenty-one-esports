<?php

namespace App\Support\TwentyOne\Stream;

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
        $s = preg_replace(self::COVERED, '', PublicName::clean((string) $s)) ?? '';

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

    /** True for a daily (correspondence) game: its clock is the time left for one move. */
    public static function isDaily(array $game): bool
    {
        return (bool) preg_match('/CORRESPONDENCE|DAILY/i', (string) ($game['mode'] ?? ''));
    }

    /**
     * The last moves as numbered rows: [number, white SAN|null, black SAN|null], numbered from the FEN's move
     * number and side to move. Without a usable list: [].
     *
     * @param  list<string>|null  $moves  the last up to 10 SAN moves, oldest first
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
            $rows[count($rows) - 1][$col] = $san;
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
     * @param  array{from: string, to: string}|null  $lastMove
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

    /** A count from the stats array, or null when it is missing or not a whole number >= 0. */
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

    /** A whole number >= 0 under $key, or null. */
    private static function whole(array $row, string $key): ?int
    {
        $v = $row[$key] ?? null;

        return is_int($v) && $v >= 0 ? $v : null;
    }

    /**
     * Direction B's ticker: the counts that are known, as text, then the site URL. Mono 20 (12 px a character),
     * 56 px between items (24 + an 8 px square + 24); counts are dropped from the end until everything fits 1200 px.
     *
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

    /** "1/0/0" or null when any part is missing. */
    public static function record(array $row): ?string
    {
        return $row['w'] === null || $row['d'] === null || $row['l'] === null ? null : $row['w'].'/'.$row['d'].'/'.$row['l'];
    }
}
