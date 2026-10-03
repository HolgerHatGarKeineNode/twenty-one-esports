<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\QrCode;
use Throwable;

/**
 * Layout of the running tournament slides (tv1..tv4, RotationPlanner::RUNNING_SCENES):
 * the tournament TV (pages::tournaments.tv, resources/css/tv.css) redrawn as
 * SVG at 1280x720, where its stage unit is 12.8 px. Header (cover, name, the
 * meta line, the QR code and its caption), the footer (the latest results,
 * the viewer badge, the scene tabs), and the bracket and grid boxes; the
 * views draw what this places.
 *
 * The client's LIVE badge owns the top right corner (x >= 1040, y < 112), so
 * the TV's QR block ends at x 1024 here.
 */
final class TvSlides
{
    /** The TV's palette (resources/css/tv.css). */
    public const GROUND = '#0A0A0B';

    public const PANEL = '#121215';

    public const RAISED = '#1E1E24';

    public const LINE = '#2A2A30';

    public const INK = '#FFFFFF';

    public const INK2 = '#ADADB0';

    public const INK3 = '#8B8B90';

    public const BTC = '#F7931A';

    public const BTC_HI = '#F9B25F';

    public const ON_BTC = '#17120A';

    public const LIVE_PANEL = '#1E1A12';

    /** The content band between header and footer. */
    public const X0 = 38;

    public const X1 = 1242;

    public const Y0 = 173;

    public const Y1 = 627;

    /**
     * The running slides, scene id => view: the TV's bracket (tv1), up now (tv2), standings (tv3), pot (tv4). Part n
     * is scene 'tv'.n (TournamentLiveSlides::runningParts()).
     */
    public const VIEWS = ['tv1' => 'stream.rotation.tv1-bracket', 'tv2' => 'stream.rotation.tv2-up-now', 'tv3' => 'stream.rotation.tv3-standings', 'tv4' => 'stream.rotation.tv4-pot'];

    /** The tabs, by part (TournamentLiveSlides::runningParts()), as the TV names its scenes. */
    public const TABS = [1 => 'Bracket', 2 => 'Up now', 3 => 'Standings', 4 => 'Prize pool'];

    /** @var array<string, array{d: string, n: int}> url => QR path, for the process lifetime */
    private static array $qr = [];

    /**
     * The header: the name in up to two lines and the meta line wrapped into
     * the title column (game line, format, the Live chip or the status, the
     * matches played), centred on the cover as the TV centres its row.
     *
     * @param  array<string, mixed>  $t
     * @return array{title: array{lines: list<string>, size: float, font: string}, titleY: list<float>, meta: list<array{kind: string, text: string, x: float, y: float, w: float}>}
     */
    public static function header(array $t): array
    {
        $x0 = 222.0;
        $w = 394.0;
        // One line as the TV sets it (3u down to 2u), two lines only when one would cut the name.
        $name = RotationKit::text($t, 'name', 'Tournament');
        $title = RotationKit::headline($name, [38, 34, 30, 26], $w, 1);
        if (implode(' ', $title['lines']) !== RotationKit::clean($name)) {
            $title = RotationKit::headline($name, [32, 28, 24], $w, 2);
        }
        $tv = is_array($t['tv'] ?? null) ? $t['tv'] : [];
        $progress = is_array($t['progress'] ?? null) ? $t['progress'] : [];
        $tokens = [
            ['plain', RotationKit::clean(is_string($tv['gameLine'] ?? null) ? $tv['gameLine'] : RotationKit::text($t, 'game'))],
            ['plain', RotationKit::clean(is_string($tv['formatLabel'] ?? null) ? $tv['formatLabel'] : RotationKit::text($t, 'format'))],
            ($t['status'] ?? 'Live now') === 'Live now' ? ['live', 'Live'] : ['status', RotationKit::text($t, 'status', 'Live now')],
        ];

        if (is_int($progress['played'] ?? null) && is_int($progress['total'] ?? null)) {
            $tokens[] = ['plain', $progress['played'].' of '.$progress['total'].' matches played'];
        }

        $meta = [];
        $x = $x0;
        $line = 0;

        foreach ($tokens as [$kind, $text]) {
            if ($text === '') {
                continue;
            }

            $tw = $kind === 'live' ? RotationKit::width('Live', RotationKit::MONO, 16) + 31 : RotationKit::width($text, RotationKit::MONO, 16);

            if ($x > $x0 && $x + $tw > $x0 + $w) {
                $x = $x0;
                $line++;
            }

            $text = $kind === 'live' ? $text : RotationKit::fit($text, RotationKit::MONO, 16, $w);
            $meta[] = ['kind' => $kind, 'text' => $text, 'x' => $x, 'y' => (float) $line, 'w' => min($tw, $w)];
            $x += $tw + 19;
        }

        $lines = count($title['lines']);
        $titlePitch = round($title['size'] * 1.1);
        $metaLines = $line + 1;
        $height = $lines * $titlePitch + 6 + $metaLines * 22;
        $top = 90 - $height / 2;
        $titleY = [];

        for ($i = 0; $i < $lines; $i++) {
            $titleY[] = round($top + ($i + 1) * $titlePitch - $title['size'] * 0.24, 1);
        }

        foreach ($meta as &$token) {
            $token['y'] = round($top + $lines * $titlePitch + 6 + ($token['y'] + 1) * 22 - 6, 1);
        }
        unset($token);

        return ['title' => $title, 'titleY' => $titleY, 'meta' => $meta];
    }

    /**
     * The QR code of `$url` as one path in a 0..n square (dark modules), null when it cannot be drawn.
     *
     * @return array{d: string, n: int}|null
     */
    public static function qr(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        if (! isset(self::$qr[$url])) {
            try {
                $matrix = QrCode::matrix($url);
            } catch (Throwable) {
                return null;
            }

            $d = '';
            foreach ($matrix as $y => $row) {
                foreach ($row as $x => $dark) {
                    if ($dark) {
                        $d .= 'M'.$x.' '.$y.'h1v1h-1z';
                    }
                }
            }

            self::$qr[$url] = ['d' => $d, 'n' => count($matrix)];
        }

        return self::$qr[$url];
    }

    /**
     * The footer: the tabs of this hold right-aligned (the active one marked), the viewer badge before them, and
     * as many of the latest results as fit the rest of the line.
     *
     * @param  array<string, mixed>  $t
     * @return array{tabs: list<array{label: string, x: float, w: float, active: bool}>, viewers: array<string, mixed>|null, results: list<array{winner: string, rest: string, x: float, restX: float}>, empty: string|null}
     */
    public static function footer(array $t, int $part, mixed $viewers): array
    {
        $parts = TournamentLiveSlides::runningParts($t);
        $parts = in_array($part, $parts, true) ? $parts : [...$parts, $part];
        sort($parts);
        $x = (float) self::X1;
        $tabs = [];

        foreach (array_reverse($parts) as $p) {
            $label = self::TABS[$p] ?? '';
            $w = RotationKit::width($label, RotationKit::MONO, 16);
            $x -= $w;
            array_unshift($tabs, ['label' => $label, 'x' => $x, 'w' => $w, 'active' => $p === $part]);
            $x -= 26;
        }

        $badge = RotationKit::viewerBadge($viewers, $x - 12, 677, RotationKit::MONO, 16, 16);
        $limit = ($badge === null ? $x : (float) $badge['x0']) - 32;
        $results = [];
        $rx = self::X0 + RotationKit::width('Latest', RotationKit::MONO, 16) + 19;

        foreach (is_array($t['results'] ?? null) ? $t['results'] : [] as $result) {
            $line = self::resultParts($result);

            if ($line === null) {
                continue;
            }

            $ww = RotationKit::width($line[0], RotationKit::MONO, 16);
            $restW = RotationKit::width($line[1], RotationKit::MONO, 16);

            if ($rx + $ww + $restW > $limit) {
                break;
            }

            // The rest starts after a space (an SVG text piece drops its own leading space).
            $results[] = ['winner' => $line[0], 'rest' => ltrim($line[1]), 'x' => $rx, 'restX' => round($rx + $ww + RotationKit::width(' ', RotationKit::MONO, 16), 1)];
            $rx += $ww + $restW + 38;
        }

        return ['tabs' => $tabs, 'viewers' => $badge, 'results' => $results,
            'empty' => $results === [] ? RotationKit::fit('Results appear here as they come in.', RotationKit::MONO, 16, $limit - (self::X0 + 70)) : null];
    }

    /**
     * A result as the TV ticker says it: the winner (bold) and the rest ("beats Hal 2–1", "advances by forfeit"),
     * a draw as "Ann and Hal draw 1–1"; null without two names.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function resultParts(mixed $result): ?array
    {
        if (! is_array($result) || ! is_array($result['winner'] ?? null) || ! is_array($result['loser'] ?? null)) {
            return null;
        }

        $winner = RotationKit::fit(is_string($result['winner']['name'] ?? null) ? $result['winner']['name'] : '', RotationKit::MONO, 16, 200);
        $loser = RotationKit::fit(is_string($result['loser']['name'] ?? null) ? $result['loser']['name'] : '', RotationKit::MONO, 16, 200);

        if ($winner === '' || $loser === '') {
            return null;
        }

        $score = is_string($result['label'] ?? null) ? ' '.RotationKit::clean($result['label']) : '';

        return match (true) {
            is_string($result['how'] ?? null) => [$winner, ' advances'.$result['how']],
            ($result['draw'] ?? false) === true => [$winner, ' and '.$loser.' draw'.$score],
            default => [$winner, ' beats '.$loser.$score],
        };
    }

    /**
     * A box's size factor as the TV sets it: full size up to 4 rows, down to 0.66.
     */
    public static function k(int $rows, int $full = 4): float
    {
        return max(0.66, min(1.0, $full / max(1, $rows)));
    }

    /**
     * The height of a match box at factor `$k` (two sides, the TV's padding).
     */
    public static function boxHeight(float $k): float
    {
        $fs = 24.0 * $k;

        return round(2 * $fs * 1.25 + $fs * 0.2 + $fs * 0.7, 1);
    }

    /**
     * The whole bracket as the tournament TV draws it, scaled to the content band: the sections stacked (upper over
     * lower) on one grid of columns, each section as tall as its fullest round, the boxes of a round spread over
     * that height; a grand final section (its boxes all grand final or reset) stands as its own column on the right,
     * centred on the sections it closes. Every box is lined to the box its winner goes to (`from`), lit once
     * decided.
     *
     * @param  array<string, mixed>  $tree  TournamentLiveSlides `tv.tree`
     * @return array{k: float, titles: list<array{text: string, x: float, y: float}>, labels: list<array{text: string, x: float, y: float, w: float}>, boxes: list<array{x: float, y: float, w: float, h: float, box: array<string, mixed>}>, lines: list<array{d: string, lit: bool}>}
     */
    public static function tree(array $tree, float $top): array
    {
        $stacked = [];
        $side = [];

        foreach ((array) ($tree['sections'] ?? []) as $section) {
            $columns = array_values(array_filter((array) ($section['columns'] ?? []), fn (mixed $c): bool => is_array($c) && ($c['boxes'] ?? []) !== []));
            if ($columns === []) {
                continue;
            }
            $final = true;
            foreach ($columns as $column) {
                foreach ($column['boxes'] as $box) {
                    $final = $final && in_array($box['bracket'] ?? null, ['grand-final', 'reset'], true);
                }
            }
            $entry = ['title' => is_string($section['title'] ?? null) ? $section['title'] : '', 'columns' => $columns,
                'rows' => max(array_map(fn (array $c): int => count($c['boxes']), $columns))];
            if ($final && $stacked !== []) {
                $side[] = $entry;
            } else {
                $stacked[] = $entry;
            }
        }

        $sideCols = array_sum(array_map(fn (array $s): int => count($s['columns']), $side));
        $gridCols = max(1, ...array_map(fn (array $s): int => count($s['columns']), $stacked ?: [['columns' => [[]]]])) + $sideCols;
        $gapX = $gridCols > 5 ? 16.0 : 24.0;
        $colW = (self::X1 - self::X0 - $gapX * ($gridCols - 1)) / $gridCols;
        $head = 44.0;
        $sectionGap = 10.0;
        $rows = max(1, array_sum(array_column($stacked, 'rows')));
        $unit = (self::Y1 - $top - count($stacked) * $head - max(0, count($stacked) - 1) * $sectionGap) / $rows;
        $h = round(min(self::boxHeight(1.0), $unit * 0.88), 1);
        $k = max(0.3, $h / self::boxHeight(1.0));
        $titles = [];
        $labels = [];
        $boxes = [];
        $at = [];
        $place = function (array $column, float $x, float $y0, float $height) use (&$boxes, &$at, $colW, $h): void {
            $count = count($column['boxes']);
            $room = max(0.0, ($height - $count * $h) / $count);
            foreach (array_values($column['boxes']) as $i => $box) {
                $y = round($y0 + $room / 2 + $i * ($h + $room), 1);
                $boxes[] = ['x' => round($x, 1), 'y' => $y, 'w' => round($colW, 1), 'h' => $h, 'box' => $box];
                $at[(string) ($box['key'] ?? '')] = [$x, $y, ($box['state'] ?? '') === 'done'];
            }
        };

        $y = $top;
        $areaTop = null;
        foreach ($stacked as $section) {
            if ($section['title'] !== '') {
                $titles[] = ['text' => $section['title'], 'x' => self::X0, 'y' => $y + 14];
            }
            $boxTop = $y + $head;
            $areaTop ??= $boxTop;
            $height = $section['rows'] * $unit;
            foreach ($section['columns'] as $ci => $column) {
                $x = self::X0 + $ci * ($colW + $gapX);
                $labels[] = ['text' => (string) ($column['label'] ?? ''), 'x' => round($x, 1), 'y' => $y + 36, 'w' => round($colW, 1)];
                $place($column, $x, $boxTop, $height);
            }
            $y = $boxTop + $height + $sectionGap;
        }

        $areaBottom = $y - $sectionGap;
        $ci = $gridCols - $sideCols;
        foreach ($side as $section) {
            $titles[] = ['text' => $section['title'], 'x' => self::X0 + $ci * ($colW + $gapX), 'y' => $top + 14];
            foreach ($section['columns'] as $column) {
                $x = self::X0 + $ci * ($colW + $gapX);
                if ((string) ($column['label'] ?? '') !== $section['title']) {
                    $labels[] = ['text' => (string) ($column['label'] ?? ''), 'x' => round($x, 1), 'y' => $top + 36, 'w' => round($colW, 1)];
                }
                $place($column, $x, $areaTop ?? $top + $head, $areaBottom - ($areaTop ?? $top + $head));
                $ci++;
            }
        }

        $lines = [];
        foreach ($boxes as $target) {
            foreach ((array) ($target['box']['from'] ?? []) as $from) {
                $source = is_string($from) ? ($at[$from] ?? null) : null;
                if ($source === null || $source[0] >= $target['x']) {
                    continue;
                }
                $x1 = $source[0] + $colW;
                $y1 = $source[1] + $h / 2;
                $x2 = $target['x'];
                $y2 = $target['y'] + $h / 2;
                $mid = round($x2 - $gapX / 2, 1);
                $lines[] = ['d' => 'M'.round($x1, 1).' '.round($y1, 1).'H'.$mid.'V'.round($y2, 1).'H'.round($x2, 1), 'lit' => $source[2]];
            }
        }

        return ['k' => round($k, 3), 'titles' => $titles, 'labels' => $labels, 'boxes' => $boxes, 'lines' => $lines];
    }

    /**
     * The board's bracket as the TV lays it out: the columns side by side (3u apart), each with its label and its
     * boxes spread over the height (space-around), and a line from every box shown to the box it feeds.
     *
     * @param  array<string, mixed>  $board  a TournamentLiveSlides board of kind 'bracket'
     * @return array{k: float, columns: list<array{label: string, x: float, w: float, boxes: list<array{x: float, y: float, w: float, h: float, box: array<string, mixed>}>}>, lines: list<array{d: string, lit: bool}>}
     */
    public static function bracket(array $board, float $top): array
    {
        $columns = array_values(array_filter(is_array($board['columns'] ?? null) ? $board['columns'] : [], is_array(...)));
        $n = max(1, count($columns));
        $gap = 38.0;
        $w = (self::X1 - self::X0 - $gap * ($n - 1)) / $n;
        $rows = max(1, ...array_map(fn (array $c): int => count($c['matches'] ?? []), $columns ?: [[]]));
        $k = self::k($rows);
        $h = self::boxHeight($k);
        $boxTop = $top + 26;
        $space = self::Y1 - $boxTop;
        $out = [];
        $at = [];

        foreach ($columns as $ci => $column) {
            $x = self::X0 + $ci * ($w + $gap);
            $matches = array_values(array_filter($column['matches'] ?? [], is_array(...)));
            $count = max(1, count($matches));
            // space-around: equal room around every box, at least 6.4 px between boxes.
            $room = max(0.0, ($space - $count * $h) / $count);
            $boxes = [];

            foreach ($matches as $mi => $match) {
                $y = round($boxTop + $room / 2 + $mi * ($h + $room), 1);
                $boxes[] = ['x' => round($x, 1), 'y' => $y, 'w' => round($w, 1), 'h' => $h, 'box' => $match];
                $at[(string) ($match['key'] ?? '')] = [$ci, $x, $y, $w, $h, ($match['state'] ?? '') === 'done'];
            }

            $total = is_int($column['total'] ?? null) ? $column['total'] : count($matches);
            $label = RotationKit::clean((string) ($column['label'] ?? ''));
            $out[] = ['label' => $total > count($matches) ? $label.' ('.count($matches).' of '.$total.')' : $label, 'x' => round($x, 1), 'w' => round($w, 1), 'boxes' => $boxes];
        }

        $lines = [];

        foreach ($out as $column) {
            foreach ($column['boxes'] as $target) {
                foreach ((array) ($target['box']['from'] ?? []) as $from) {
                    $source = is_string($from) ? ($at[$from] ?? null) : null;

                    if ($source === null) {
                        continue;
                    }

                    [, $sx, $sy, $sw, $sh, $done] = $source;
                    $x1 = $sx + $sw;
                    $y1 = $sy + $sh / 2;
                    $x2 = $target['x'];
                    $y2 = $target['y'] + $target['h'] / 2;
                    $mid = round(($x1 + $x2) / 2, 1);
                    $lines[] = ['d' => 'M'.round($x1, 1).' '.round($y1, 1).'H'.$mid.'V'.round($y2, 1).'H'.round($x2, 1), 'lit' => $done];
                }
            }
        }

        return ['k' => $k, 'columns' => $out, 'lines' => $lines];
    }
}
