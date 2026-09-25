@php
    /**
     * Stream scene: ONE live blitz chess game as a 1280x720 still (SVG -> rsvg-convert -> PNG).
     * No animation; the supervisor re-renders it once per second while a clock runs.
     *
     * Data contract (all keys required unless marked optional):
     *
     * @var array{name: string, clockMs: int, toMove: bool} $white  public profile name, remaining ms (<= 0 renders 0:00)
     * @var array{name: string, clockMs: int, toMove: bool} $black
     * @var string $fen                                  any FEN; only the placement field is read
     * @var array{from: string, to: string}|null $lastMove  squares like "e2"/"e4"; null before the first move
     * @var string $mode                                 e.g. "LIVE · CHESS BLITZ 5+3 · CASUAL" (never "rated"/Elo)
     * @var string $stats                                ONE line of real stats, e.g. "14 games played · 1 game live · 3 clans"
     * @var string $url                                  e.g. "esports.einundzwanzig.space"
     * @var string|null $result                          optional; set after the game ends (scene stays 60 s),
     *                                                   e.g. "0-1 · White ran out of time"; replaces $mode in the header
     *
     * Fonts: "Unbounded" (800) and "JetBrains Mono" (700, latin + latin-ext files) through a private FONTCONFIG_FILE;
     * nothing else is installed. Variable strings are filtered to the code points the mono files cover ($covered);
     * a name that filters to nothing shows as "White player" / "Black player".
     *
     * Truncation without font metrics: every user-visible string of variable length is set in JetBrains Mono,
     * whose advance is exactly 0.6 em for every glyph it has. Width = chars x 0.6 x size, so a char budget is
     * a pixel budget: names 25 chars at 28 px (<= 420 px), mode 40 at 18 px (<= 432 px), stats 48 at 18 px
     * (<= 518 px). Longer strings keep budget-1 chars plus "…". Counted in code points (mb_*), not graphemes.
     *
     * Chess pieces: "cburnett" set by Colin M.L. Burnett (Wikimedia Commons, File:Chess_{k,q,r,b,n,p}{l,d}t45.svg),
     * used under its BSD 3-clause option (the files are multi-licensed GFDL 1.2+ / CC BY-SA 3.0 / BSD / GPL 2+).
     * Changes: element ids removed, whitespace collapsed, wrapped in <symbol>.
     */
    // Code points the two JetBrains Mono files cover (latin + latin-ext subsets, the same two the site ships).
    // Anything else (emoji, Cyrillic, CJK, control characters) would render as a missing-glyph box, so it is dropped.
    $covered = '/[^\x{0020}-\x{007E}\x{00A0}-\x{0131}\x{0134}-\x{017F}\x{018F}\x{0192}\x{01A0}-\x{01A1}\x{01AF}-\x{01B0}\x{01CD}-\x{01CE}\x{01E6}-\x{01E7}\x{01EA}-\x{01EB}\x{01F4}-\x{01F5}\x{01FC}-\x{01FF}\x{0218}-\x{021B}\x{0232}-\x{0233}\x{0237}\x{0259}\x{02B9}-\x{02BA}\x{02BC}\x{02C6}-\x{02C7}\x{02C9}\x{02DA}\x{02DC}-\x{02DD}\x{02F3}\x{02F7}\x{0300}-\x{0301}\x{0303}-\x{0304}\x{0308}-\x{0309}\x{0323}\x{1E80}-\x{1E85}\x{1E9E}\x{1EF2}-\x{1EF9}\x{2013}-\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}\x{2022}\x{2026}\x{2032}-\x{2033}\x{2039}-\x{203A}\x{2044}\x{20AB}-\x{20AC}\x{20AE}\x{20BD}\x{20BF}\x{2113}\x{2122}\x{2191}\x{2193}\x{2212}\x{2215}]+/u';
    // Shared with the 30311 texts: control, bidi and zero-width characters out,
    // whitespace (newlines included) collapsed; then what the fonts cannot show.
    $clean = static function (string $s) use ($covered): string {
        $s = preg_replace($covered, '', \App\Support\TwentyOne\Stream\PublicName::clean($s)) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    };
    $fit = static function (string $s, int $max) use ($clean): string {
        $s = $clean($s);

        return mb_strlen($s) <= $max ? $s : rtrim(mb_substr($s, 0, $max - 1)).'…';
    };
    $clock = static function (int $ms): string {
        $s = intdiv(max(0, $ms), 1000);

        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };

    // Board: 8 x 78 px squares inside an 8 px frame, x/y 40..680.
    $sq = 78;
    $bx = 48;
    $by = 48;
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
                $pieces[] = ['id' => (ctype_upper($ch) ? 'w' : 'b').strtolower($ch), 'x' => $bx + $f * $sq, 'y' => $by + $r * $sq];
            }
            $f++;
        }
    }
    for ($r = 0; $r < 8; $r++) {
        for ($f = 0; $f < 8; $f++) {
            $name = 'abcdefgh'[$f].(8 - $r);
            $light = ($r + $f) % 2 === 0;
            $squares[] = [
                'x' => $bx + $f * $sq, 'y' => $by + $r * $sq,
                'fill' => in_array($name, $last, true) ? ($light ? '#F4C47F' : '#B8741F') : ($light ? '#CFCFD4' : '#62626C'),
            ];
        }
    }

    // Player cards: black on top, white below (the board shows white at the bottom).
    $cards = [];
    foreach ([['p' => $black, 'king' => 'bk', 'y' => 136, 'fallback' => 'Black player'], ['p' => $white, 'king' => 'wk', 'y' => 312, 'fallback' => 'White player']] as $c) {
        $ms = (int) $c['p']['clockMs'];
        $active = (bool) $c['p']['toMove'];
        $low = $ms < 10000;
        $text = $clock($ms);
        $digits = [];
        $cx = 740;
        foreach (str_split($text) as $ch) {
            $w = $ch === ':' ? 30 : 75;
            $digits[] = ['ch' => $ch, 'x' => $cx + $w / 2];
            $cx += $w;
        }
        $cards[] = [
            'y' => $c['y'], 'king' => $c['king'], 'name' => $fit((string) $c['p']['name'], 25) ?: $c['fallback'],
            'digits' => $digits,
            'fill' => $active ? ($low ? '#F87171' : '#F7931A') : '#121215',
            'stroke' => $active ? 'none' : '#2A2A30',
            'ink' => $active ? '#17120A' : '#FFFFFF',
            'clockInk' => $active ? '#17120A' : ($low ? '#F87171' : '#FFFFFF'),
        ];
    }
    $headline = $fit(($result ?? null) ?: $mode, 40);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
<defs>
<symbol id="mark" viewBox="0 0 1024 1024"><rect width="1024" height="1024" fill="#F7931A"/><g fill="#17120A"><path transform="translate(152.53,712.00) scale(0.53333,-0.53333)" d="M31 476Q38 566 91.5 631.5Q145 697 234.0 732.0Q323 767 434 767Q540 767 619.5 735.0Q699 703 743.0 646.5Q787 590 787 515Q787 456 754.5 406.0Q722 356 654.0 308.5Q586 261 477 212L286 124L281 182H807V0H50V160L373 337Q446 377 485.0 403.5Q524 430 540.0 452.5Q556 475 556 501Q556 528 541.0 548.0Q526 568 496.5 579.0Q467 590 425 590Q370 590 335.5 574.5Q301 559 283.5 533.0Q266 507 262 476Z"/><path transform="translate(605.87,712.00) scale(0.53333,-0.53333)" d="M467 750V0H237V667L342 570L15 513V691L297 750Z"/><rect x="732.27" y="252.00" width="34.35" height="61.00"/><rect x="732.27" y="711.00" width="34.35" height="61.00"/><rect x="820.59" y="252.00" width="34.35" height="61.00"/><rect x="820.59" y="711.00" width="34.35" height="61.00"/></g></symbol>
<symbol id="p-wk" viewBox="0 0 45 45"><g fill="none" fill-rule="evenodd" stroke="#000" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path stroke-linejoin="miter" d="M22.5 11.63V6M20 8h5"/><path fill="#fff" stroke-linecap="butt" stroke-linejoin="miter" d="M22.5 25s4.5-7.5 3-10.5c0 0-1-2.5-3-2.5s-3 2.5-3 2.5c-1.5 3 3 10.5 3 10.5"/><path fill="#fff" d="M12.5 37c5.5 3.5 14.5 3.5 20 0v-7s9-4.5 6-10.5c-4-6.5-13.5-3.5-16 4V27v-3.5c-2.5-7.5-12-10.5-16-4-3 6 6 10.5 6 10.5v7"/><path d="M12.5 30c5.5-3 14.5-3 20 0m-20 3.5c5.5-3 14.5-3 20 0m-20 3.5c5.5-3 14.5-3 20 0"/></g></symbol>
<symbol id="p-wq" viewBox="0 0 45 45"><g style="fill:#ffffff;stroke:#000000;stroke-width:1.5;stroke-linejoin:round"><path d="M 9,26 C 17.5,24.5 30,24.5 36,26 L 38.5,13.5 L 31,25 L 30.7,10.9 L 25.5,24.5 L 22.5,10 L 19.5,24.5 L 14.3,10.9 L 14,25 L 6.5,13.5 L 9,26 z"/><path d="M 9,26 C 9,28 10.5,28 11.5,30 C 12.5,31.5 12.5,31 12,33.5 C 10.5,34.5 11,36 11,36 C 9.5,37.5 11,38.5 11,38.5 C 17.5,39.5 27.5,39.5 34,38.5 C 34,38.5 35.5,37.5 34,36 C 34,36 34.5,34.5 33,33.5 C 32.5,31 32.5,31.5 33.5,30 C 34.5,28 36,28 36,26 C 27.5,24.5 17.5,24.5 9,26 z"/><path d="M 11.5,30 C 15,29 30,29 33.5,30" style="fill:none"/><path d="M 12,33.5 C 18,32.5 27,32.5 33,33.5" style="fill:none"/><circle cx="6" cy="12" r="2" /><circle cx="14" cy="9" r="2" /><circle cx="22.5" cy="8" r="2" /><circle cx="31" cy="9" r="2" /><circle cx="39" cy="12" r="2" /></g></symbol>
<symbol id="p-wr" viewBox="0 0 45 45"><g style="opacity:1; fill:#ffffff; fill-opacity:1; fill-rule:evenodd; stroke:#000000; stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.3)"><path d="M 9,39 L 36,39 L 36,36 L 9,36 L 9,39 z " style="stroke-linecap:butt;" /><path d="M 12,36 L 12,32 L 33,32 L 33,36 L 12,36 z " style="stroke-linecap:butt;" /><path d="M 11,14 L 11,9 L 15,9 L 15,11 L 20,11 L 20,9 L 25,9 L 25,11 L 30,11 L 30,9 L 34,9 L 34,14" style="stroke-linecap:butt;" /><path d="M 34,14 L 31,17 L 14,17 L 11,14" /><path d="M 31,17 L 31,29.5 L 14,29.5 L 14,17" style="stroke-linecap:butt; stroke-linejoin:miter;" /><path d="M 31,29.5 L 32.5,32 L 12.5,32 L 14,29.5" /><path d="M 11,14 L 34,14" style="fill:none; stroke:#000000; stroke-linejoin:miter;" /></g></symbol>
<symbol id="p-wb" viewBox="0 0 45 45"><g style="opacity:1; fill:none; fill-rule:evenodd; fill-opacity:1; stroke:#000000; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.6)"><g style="fill:#ffffff; stroke:#000000; stroke-linecap:butt;"><path d="M 9,36 C 12.39,35.03 19.11,36.43 22.5,34 C 25.89,36.43 32.61,35.03 36,36 C 36,36 37.65,36.54 39,38 C 38.32,38.97 37.35,38.99 36,38.5 C 32.61,37.53 25.89,38.96 22.5,37.5 C 19.11,38.96 12.39,37.53 9,38.5 C 7.65,38.99 6.68,38.97 6,38 C 7.35,36.54 9,36 9,36 z"/><path d="M 15,32 C 17.5,34.5 27.5,34.5 30,32 C 30.5,30.5 30,30 30,30 C 30,27.5 27.5,26 27.5,26 C 33,24.5 33.5,14.5 22.5,10.5 C 11.5,14.5 12,24.5 17.5,26 C 17.5,26 15,27.5 15,30 C 15,30 14.5,30.5 15,32 z"/><path d="M 25 8 A 2.5 2.5 0 1 1 20,8 A 2.5 2.5 0 1 1 25 8 z"/></g><path d="M 17.5,26 L 27.5,26 M 15,30 L 30,30 M 22.5,15.5 L 22.5,20.5 M 20,18 L 25,18" style="fill:none; stroke:#000000; stroke-linejoin:miter;"/></g></symbol>
<symbol id="p-wn" viewBox="0 0 45 45"><g style="opacity:1; fill:none; fill-opacity:1; fill-rule:evenodd; stroke:#000000; stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.3)"><path d="M 22,10 C 32.5,11 38.5,18 38,39 L 15,39 C 15,30 25,32.5 23,18" style="fill:#ffffff; stroke:#000000;" /><path d="M 24,18 C 24.38,20.91 18.45,25.37 16,27 C 13,29 13.18,31.34 11,31 C 9.958,30.06 12.41,27.96 11,28 C 10,28 11.19,29.23 10,30 C 9,30 5.997,31 6,26 C 6,24 12,14 12,14 C 12,14 13.89,12.1 14,10.5 C 13.27,9.506 13.5,8.5 13.5,7.5 C 14.5,6.5 16.5,10 16.5,10 L 18.5,10 C 18.5,10 19.28,8.008 21,7 C 22,7 22,10 22,10" style="fill:#ffffff; stroke:#000000;" /><path d="M 9.5 25.5 A 0.5 0.5 0 1 1 8.5,25.5 A 0.5 0.5 0 1 1 9.5 25.5 z" style="fill:#000000; stroke:#000000;" /><path d="M 15 15.5 A 0.5 1.5 0 1 1 14,15.5 A 0.5 1.5 0 1 1 15 15.5 z" transform="matrix(0.866,0.5,-0.5,0.866,9.693,-5.173)" style="fill:#000000; stroke:#000000;" /></g></symbol>
<symbol id="p-wp" viewBox="0 0 45 45"><path d="m 22.5,9 c -2.21,0 -4,1.79 -4,4 0,0.89 0.29,1.71 0.78,2.38 C 17.33,16.5 16,18.59 16,21 c 0,2.03 0.94,3.84 2.41,5.03 C 15.41,27.09 11,31.58 11,39.5 H 34 C 34,31.58 29.59,27.09 26.59,26.03 28.06,24.84 29,23.03 29,21 29,18.59 27.67,16.5 25.72,15.38 26.21,14.71 26.5,13.89 26.5,13 c 0,-2.21 -1.79,-4 -4,-4 z" style="opacity:1; fill:#ffffff; fill-opacity:1; fill-rule:nonzero; stroke:#000000; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:miter; stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;"/></symbol>
<symbol id="p-bk" viewBox="0 0 45 45"><g style="fill:none; fill-opacity:1; fill-rule:evenodd; stroke:#000000; stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;"><path d="M 22.5,11.63 L 22.5,6" style="fill:none; stroke:#000000; stroke-linejoin:miter;"/><path d="M 22.5,25 C 22.5,25 27,17.5 25.5,14.5 C 25.5,14.5 24.5,12 22.5,12 C 20.5,12 19.5,14.5 19.5,14.5 C 18,17.5 22.5,25 22.5,25" style="fill:#000000;fill-opacity:1; stroke-linecap:butt; stroke-linejoin:miter;"/><path d="M 12.5,37 C 18,40.5 27,40.5 32.5,37 L 32.5,30 C 32.5,30 41.5,25.5 38.5,19.5 C 34.5,13 25,16 22.5,23.5 L 22.5,27 L 22.5,23.5 C 20,16 10.5,13 6.5,19.5 C 3.5,25.5 12.5,30 12.5,30 L 12.5,37" style="fill:#000000; stroke:#000000;"/><path d="M 20,8 L 25,8" style="fill:none; stroke:#000000; stroke-linejoin:miter;"/><path d="M 32,29.5 C 32,29.5 40.5,25.5 38.03,19.85 C 34.15,14 25,18 22.5,24.5 L 22.5,26.6 L 22.5,24.5 C 20,18 10.85,14 6.97,19.85 C 4.5,25.5 13,29.5 13,29.5" style="fill:none; stroke:#ffffff;"/><path d="M 12.5,30 C 18,27 27,27 32.5,30 M 12.5,33.5 C 18,30.5 27,30.5 32.5,33.5 M 12.5,37 C 18,34 27,34 32.5,37" style="fill:none; stroke:#ffffff;"/></g></symbol>
<symbol id="p-bq" viewBox="0 0 45 45"><g style="fill:#000000;stroke:#000000;stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round"><path d="M 9,26 C 17.5,24.5 30,24.5 36,26 L 38.5,13.5 L 31,25 L 30.7,10.9 L 25.5,24.5 L 22.5,10 L 19.5,24.5 L 14.3,10.9 L 14,25 L 6.5,13.5 L 9,26 z" style="stroke-linecap:butt;fill:#000000" /><path d="m 9,26 c 0,2 1.5,2 2.5,4 1,1.5 1,1 0.5,3.5 -1.5,1 -1,2.5 -1,2.5 -1.5,1.5 0,2.5 0,2.5 6.5,1 16.5,1 23,0 0,0 1.5,-1 0,-2.5 0,0 0.5,-1.5 -1,-2.5 -0.5,-2.5 -0.5,-2 0.5,-3.5 1,-2 2.5,-2 2.5,-4 -8.5,-1.5 -18.5,-1.5 -27,0 z" /><path d="M 11.5,30 C 15,29 30,29 33.5,30" /><path d="m 12,33.5 c 6,-1 15,-1 21,0" /><circle cx="6" cy="12" r="2" /><circle cx="14" cy="9" r="2" /><circle cx="22.5" cy="8" r="2" /><circle cx="31" cy="9" r="2" /><circle cx="39" cy="12" r="2" /><path d="M 11,38.5 A 35,35 1 0 0 34,38.5" style="fill:none; stroke:#000000;stroke-linecap:butt;" /><g style="fill:none; stroke:#ffffff;"><path d="M 11,29 A 35,35 1 0 1 34,29" /><path d="M 12.5,31.5 L 32.5,31.5" /><path d="M 11.5,34.5 A 35,35 1 0 0 33.5,34.5" /><path d="M 10.5,37.5 A 35,35 1 0 0 34.5,37.5" /></g></g></symbol>
<symbol id="p-br" viewBox="0 0 45 45"><g style="opacity:1; fill:#000000; fill-opacity:1; fill-rule:evenodd; stroke:#000000; stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.3)"><path d="M 9,39 L 36,39 L 36,36 L 9,36 L 9,39 z " style="stroke-linecap:butt;" /><path d="M 12.5,32 L 14,29.5 L 31,29.5 L 32.5,32 L 12.5,32 z " style="stroke-linecap:butt;" /><path d="M 12,36 L 12,32 L 33,32 L 33,36 L 12,36 z " style="stroke-linecap:butt;" /><path d="M 14,29.5 L 14,16.5 L 31,16.5 L 31,29.5 L 14,29.5 z " style="stroke-linecap:butt;stroke-linejoin:miter;" /><path d="M 14,16.5 L 11,14 L 34,14 L 31,16.5 L 14,16.5 z " style="stroke-linecap:butt;" /><path d="M 11,14 L 11,9 L 15,9 L 15,11 L 20,11 L 20,9 L 25,9 L 25,11 L 30,11 L 30,9 L 34,9 L 34,14 L 11,14 z " style="stroke-linecap:butt;" /><path d="M 12,35.5 L 33,35.5 L 33,35.5" style="fill:none; stroke:#ffffff; stroke-width:1; stroke-linejoin:miter;" /><path d="M 13,31.5 L 32,31.5" style="fill:none; stroke:#ffffff; stroke-width:1; stroke-linejoin:miter;" /><path d="M 14,29.5 L 31,29.5" style="fill:none; stroke:#ffffff; stroke-width:1; stroke-linejoin:miter;" /><path d="M 14,16.5 L 31,16.5" style="fill:none; stroke:#ffffff; stroke-width:1; stroke-linejoin:miter;" /><path d="M 11,14 L 34,14" style="fill:none; stroke:#ffffff; stroke-width:1; stroke-linejoin:miter;" /></g></symbol>
<symbol id="p-bb" viewBox="0 0 45 45"><g style="opacity:1; fill:none; fill-rule:evenodd; fill-opacity:1; stroke:#000000; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.6)"><g style="fill:#000000; stroke:#000000; stroke-linecap:butt;"><path d="M 9,36 C 12.39,35.03 19.11,36.43 22.5,34 C 25.89,36.43 32.61,35.03 36,36 C 36,36 37.65,36.54 39,38 C 38.32,38.97 37.35,38.99 36,38.5 C 32.61,37.53 25.89,38.96 22.5,37.5 C 19.11,38.96 12.39,37.53 9,38.5 C 7.65,38.99 6.68,38.97 6,38 C 7.35,36.54 9,36 9,36 z"/><path d="M 15,32 C 17.5,34.5 27.5,34.5 30,32 C 30.5,30.5 30,30 30,30 C 30,27.5 27.5,26 27.5,26 C 33,24.5 33.5,14.5 22.5,10.5 C 11.5,14.5 12,24.5 17.5,26 C 17.5,26 15,27.5 15,30 C 15,30 14.5,30.5 15,32 z"/><path d="M 25 8 A 2.5 2.5 0 1 1 20,8 A 2.5 2.5 0 1 1 25 8 z"/></g><path d="M 17.5,26 L 27.5,26 M 15,30 L 30,30 M 22.5,15.5 L 22.5,20.5 M 20,18 L 25,18" style="fill:none; stroke:#ffffff; stroke-linejoin:miter;"/></g></symbol>
<symbol id="p-bn" viewBox="0 0 45 45"><g style="opacity:1; fill:none; fill-opacity:1; fill-rule:evenodd; stroke:#000000; stroke-width:1.5; stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;" transform="translate(0,0.3)"><path d="M 22,10 C 32.5,11 38.5,18 38,39 L 15,39 C 15,30 25,32.5 23,18" style="fill:#000000; stroke:#000000;" /><path d="M 24,18 C 24.38,20.91 18.45,25.37 16,27 C 13,29 13.18,31.34 11,31 C 9.958,30.06 12.41,27.96 11,28 C 10,28 11.19,29.23 10,30 C 9,30 5.997,31 6,26 C 6,24 12,14 12,14 C 12,14 13.89,12.1 14,10.5 C 13.27,9.506 13.5,8.5 13.5,7.5 C 14.5,6.5 16.5,10 16.5,10 L 18.5,10 C 18.5,10 19.28,8.008 21,7 C 22,7 22,10 22,10" style="fill:#000000; stroke:#000000;" /><path d="M 9.5 25.5 A 0.5 0.5 0 1 1 8.5,25.5 A 0.5 0.5 0 1 1 9.5 25.5 z" style="fill:#ffffff; stroke:#ffffff;" /><path d="M 15 15.5 A 0.5 1.5 0 1 1 14,15.5 A 0.5 1.5 0 1 1 15 15.5 z" transform="matrix(0.866,0.5,-0.5,0.866,9.693,-5.173)" style="fill:#ffffff; stroke:#ffffff;" /><path d="M 24.55,10.4 L 24.1,11.85 L 24.6,12 C 27.75,13 30.25,14.49 32.5,18.75 C 34.75,23.01 35.75,29.06 35.25,39 L 35.2,39.5 L 37.45,39.5 L 37.5,39 C 38,28.94 36.62,22.15 34.25,17.66 C 31.88,13.17 28.46,11.02 25.06,10.5 L 24.55,10.4 z " style="fill:#ffffff; stroke:none;" /></g></symbol>
<symbol id="p-bp" viewBox="0 0 45 45"><path d="m 22.5,9 c -2.21,0 -4,1.79 -4,4 0,0.89 0.29,1.71 0.78,2.38 C 17.33,16.5 16,18.59 16,21 c 0,2.03 0.94,3.84 2.41,5.03 C 15.41,27.09 11,31.58 11,39.5 H 34 C 34,31.58 29.59,27.09 26.59,26.03 28.06,24.84 29,23.03 29,21 29,18.59 27.67,16.5 25.72,15.38 26.21,14.71 26.5,13.89 26.5,13 c 0,-2.21 -1.79,-4 -4,-4 z" style="opacity:1; fill:#000000; fill-opacity:1; fill-rule:nonzero; stroke:#000000; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:miter; stroke-miterlimit:4; stroke-dasharray:none; stroke-opacity:1;"/></symbol>
</defs>
<rect width="1280" height="720" fill="#0A0A0B"/>

{{-- Board --}}
<rect x="40" y="40" width="640" height="640" fill="#3A2C14"/>
@foreach ($squares as $s)
<rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $sq }}" height="{{ $sq }}" fill="{{ $s['fill'] }}"/>
@endforeach
@foreach ($pieces as $p)
<use xlink:href="#p-{{ $p['id'] }}" href="#p-{{ $p['id'] }}" x="{{ $p['x'] }}" y="{{ $p['y'] }}" width="{{ $sq }}" height="{{ $sq }}"/>
@endforeach

{{-- Header: mark, wordmark, mode (or result) --}}
<use xlink:href="#mark" href="#mark" x="720" y="40" width="64" height="64"/>
<text x="804" y="66" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">TWENTY ONE ESPORTS</text>
<text x="804" y="98" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ ($result ?? null) ? '#F7931A' : '#ADADB0' }}">{{ $headline }}</text>

{{-- Players --}}
@foreach ($cards as $c)
{{-- The 2 px stroke is inset by 1 px so the card keeps to the 720..1240 column exactly. --}}
<rect x="721" y="{{ $c['y'] + 1 }}" width="518" height="158" rx="7" fill="{{ $c['fill'] }}" @if ($c['stroke'] !== 'none') stroke="{{ $c['stroke'] }}" stroke-width="2" @endif/>
<rect x="740" y="{{ $c['y'] + 18 }}" width="40" height="40" rx="4" fill="#CFCFD4"/>
<use xlink:href="#p-{{ $c['king'] }}" href="#p-{{ $c['king'] }}" x="740" y="{{ $c['y'] + 18 }}" width="40" height="40"/>
<text x="796" y="{{ $c['y'] + 48 }}" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="{{ $c['ink'] }}">{{ $c['name'] }}</text>
@foreach ($c['digits'] as $d)<text x="{{ $d['x'] }}" y="{{ $c['y'] + 140 }}" font-family="Unbounded" font-weight="800" font-size="80" fill="{{ $c['clockInk'] }}" text-anchor="middle">{{ $d['ch'] }}</text>@endforeach

@endforeach

{{-- Call to action and stats --}}
<rect x="720" y="504" width="520" height="2" fill="#2A2A30"/>
<text x="720" y="562" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">Play the next game</text>
<text x="720" y="608" font-family="JetBrains Mono" font-weight="700" font-size="30" fill="#F7931A">{{ $fit($url, 28) }}</text>
<text x="720" y="672" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $fit($stats, 48) }}</text>
</svg>
