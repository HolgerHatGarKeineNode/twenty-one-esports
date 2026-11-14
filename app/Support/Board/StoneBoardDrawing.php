<?php

namespace App\Support\Board;

use App\Support\Cards\StoneBoardArt;

/**
 * Where a Go position goes on the stream's board scene (resources/views/stream/rotation/d5-board.blade.php): the
 * board of the game's page and of its link preview (resources/js/boardGame.js drawGoBoard(), StoneBoardArt), as plain
 * SVG numbers for rsvg-convert. A slab of honey-coloured wood with its grain and its darker lower face, the lines with
 * the heavier frame, the star points, every stone with its shadow, and the ring on the stone set last (none after a
 * pass: the rules name no point for it). The colours and the stones' light are StoneBoardArt's; the view paints.
 */
final class StoneBoardDrawing
{
    /** The slab's lower face over its width, as on the link preview (StoneBoardArt::board()). */
    public const FACE = 0.018;

    /**
     * The ring on the stone set last over the distance between two lines: its radius and its width. Heavier than the
     * page's (0.2 and 0.075) for a screen across a room: on 19 x 19 at 1920 x 1080 the page's ring would be 17 px
     * across with a 2.7 px band, this one is 21 px with 3.7 px.
     */
    public const RING = ['radius' => 0.24, 'width' => 0.1];

    /** A stone's shadow on the wood over the distance between two lines: its shift and its radius (the page's). */
    public const SHADOW = ['dx' => 0.02, 'dy' => 0.07, 'radius' => 0.55];

    /** The stone of a side's card on the scene, by the letter the scene colours a side with (BoardScene::live() `colours`). */
    public const KIND = ['b' => 'black', 'w' => 'white'];

    /**
     * Whether a rules' view is a board of stones (GoRules::view()).
     *
     * @param  array<string, mixed>  $view
     */
    public static function draws(array $view): bool
    {
        return ($view['input'] ?? null) === 'stones';
    }

    /**
     * The drawing of a position's view in the square at (x, y) of side `size`, the slab's lower face inside it.
     * `slab`: the upper face and the height of the lower one; `grain`: the streaks as [x, width, opacity]; `lines`:
     * [x1, y1, x2, y2, width]; `stars`: [x, y]; `stones`: every stone with the side that plays it; `ring`: the stone
     * set last, or null.
     *
     * @param  array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, points: list<array{id: string, x: int, y: int}>, pieces: array<string, array{side: 'w'|'b', kind: string}>, stars?: list<string>}  $view
     * @param  list<string>  $last  the point of the last move
     * @return array{slab: array{x: float, y: float, w: float, h: float, face: float}, grain: list<array{0: float, 1: float, 2: float}>, lines: list<array{0: float, 1: float, 2: float, 3: float, 4: float}>, stars: list<array{0: float, 1: float}>, star: float, stones: list<array{x: float, y: float, kind: 'black'|'white', side: 'w'|'b'}>, radius: float, shadow: array{dx: float, dy: float, radius: float}, ring: array{x: float, y: float, radius: float, width: float, kind: 'black'|'white'}|null, unit: float}
     */
    public static function layout(array $view, array $last, float $x, float $y, float $size): array
    {
        $face = round($size * self::FACE);
        $top = $size - $face;
        $scale = $top / max(1, $view['width'], $view['height']);
        $left = $x + ($size - $view['width'] * $scale) / 2;
        $upper = $y + ($top - $view['height'] * $scale) / 2;
        $xs = array_column($view['points'], 'x');
        $ys = array_column($view['points'], 'y');
        // A view without points has no lines to tell from the frame and no stones.
        [$near, $far, $high, $low] = $xs === [] || $ys === [] ? [0, 0, 0, 0] : [min($xs), max($xs), min($ys), max($ys)];
        $count = count($view['points']);
        $unit = $count > 1 ? ($far - $near) / (sqrt($count) - 1) * $scale : $top;
        // The page's lines are 1 px on a board of about 560 px, its frame 1.8 px.
        $thin = max(1.0, $size / 400);

        $grain = [];

        for ($streak = 1; $streak <= 28; $streak++) {
            $thick = (0.002 + StoneBoardArt::grainAt($streak + 40) * 0.009) * $size;
            $grain[] = [round($x + StoneBoardArt::grainAt($streak) * ($size - $thick), 2), round(max(1.0, $thick), 2), round(0.025 + StoneBoardArt::grainAt($streak + 80) * 0.05, 3)];
        }

        $lines = [];

        foreach ($view['lines'] as [$x1, $y1, $x2, $y2]) {
            $edge = $x1 === $x2 ? $x1 === $near || $x1 === $far : $y1 === $high || $y1 === $low;
            $lines[] = [round($left + $x1 * $scale, 2), round($upper + $y1 * $scale, 2), round($left + $x2 * $scale, 2), round($upper + $y2 * $scale, 2), round($edge ? $thin * 1.8 : $thin, 2)];
        }

        $starIds = array_flip($view['stars'] ?? []);
        $lastId = $last[0] ?? null;
        $stars = [];
        $stones = [];
        $ring = null;

        foreach ($view['points'] as $point) {
            $px = round($left + $point['x'] * $scale, 2);
            $py = round($upper + $point['y'] * $scale, 2);
            $piece = $view['pieces'][$point['id']] ?? null;

            if (isset($starIds[$point['id']])) {
                $stars[] = [$px, $py];
            }

            if ($piece === null || ($piece['kind'] !== 'black' && $piece['kind'] !== 'white')) {
                continue;
            }

            // The drawing names a stone by its colour and by the scene's side letter: Go's first side plays black.
            $stones[] = ['x' => $px, 'y' => $py, 'kind' => $piece['kind'], 'side' => $piece['kind'] === 'black' ? 'b' : 'w'];

            if ($point['id'] === $lastId) {
                $ring = ['x' => $px, 'y' => $py, 'radius' => round($unit * self::RING['radius'], 2), 'width' => round($unit * self::RING['width'], 2), 'kind' => $piece['kind']];
            }
        }

        return [
            'slab' => ['x' => $x, 'y' => $y, 'w' => $size, 'h' => $top, 'face' => $face],
            'grain' => $grain,
            'lines' => $lines,
            'stars' => $stars,
            'star' => round($unit * ($count > 81 ? 0.12 : 0.1), 2),
            'stones' => $stones,
            'radius' => round($unit * StoneBoardArt::RADIUS, 2),
            'shadow' => ['dx' => round($unit * self::SHADOW['dx'], 2), 'dy' => round($unit * self::SHADOW['dy'], 2), 'radius' => round($unit * self::SHADOW['radius'], 2)],
            'ring' => $ring,
            'unit' => round($unit, 2),
        ];
    }
}
