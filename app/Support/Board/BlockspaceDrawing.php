<?php

namespace App\Support\Board;

/**
 * Where a Blockspace position goes in a square, for the drawings outside
 * the game page: the link preview card (PageCard) and the stream's board
 * scene (resources/views/stream/rotation/d5-board.blade.php). The board
 * page draws the same thing in the browser (resources/js/blockspace/board.js,
 * board.css): the 9 x 21 block of cells with the orange and blue stones on
 * the left, the mempool ladder beside it (the oldest transaction on top,
 * each with what its rung adds), and under it each side's yield and how
 * many transactions are still to come.
 *
 * Only what `BlockspaceRules::view()` shows goes in: the stones, the ladder
 * and the `state` piece (turn, yields, the queue's length). The hidden order
 * of the queue never reaches a drawing. The callers paint the rectangles and
 * place their own words at the anchors.
 */
final class BlockspaceDrawing
{
    /** The stones' colours, as board.css `--bs-w` / `--bs-b`. */
    public const FILL = ['w' => '#F7931A', 'b' => '#8DB4FF'];

    /** The ground behind the drawing, as board.css `--bs-rung`. */
    public const GROUND = '#15172A';

    /** An empty cell, as board.css `--bs-cell`. */
    public const CELL = '#1C1F31';

    /** A rung's box, as board.css `--bs-slab-top`. */
    public const RUNG = '#2C3049';

    /** The light ink of the labels on the ground. */
    public const INK = '#E4E4E7';

    /** The muted ink of the labels on the ground. */
    public const INK_MUTED = '#A1A1AA';

    /**
     * The drawing of a position's view in the square at (x, y) of side `size`.
     * `cells`: every cell as [x, y, side, stone side or null, last]; `rungs`:
     * the ladder's boxes with the form's cells and the bonus label's anchor;
     * `scores`: each side's swatch and number anchor, `turn` the side to move;
     * `queue`: the anchor of "N to come".
     *
     * @param  array{width: int, height: int, cells: list<array{x: int, y: int, size: int}>, points: list<array{id: string, x: int, y: int}>, pieces: array<string, array{side: 'w'|'b', kind: string}>}  $view
     * @param  list<string>  $last  the cells of the last move
     * @return array{cells: list<array{0: float, 1: float, 2: float, 3: 'w'|'b'|null, 4: bool}>, rungs: list<array{x: float, y: float, w: float, h: float, bonus: int, label: array{x: float, y: float, size: float}, form: list<array{0: float, 1: float, 2: float}>}>, scores: list<array{side: 'w'|'b', value: int, x: float, y: float, swatch: float, size: float}>, turn: 'w'|'b', queue: array{value: int, x: float, y: float, size: float}, frame: array{x: float, y: float, w: float, h: float}}
     */
    public static function layout(array $view, array $last, float $x, float $y, float $size): array
    {
        $cell = $size / BlockspaceRules::ROWS;
        $gap = round($cell * 0.08, 2);
        $boardWidth = $cell * BlockspaceRules::COLUMNS;
        $unit = max(1, $view['cells'][0]['size'] ?? 1);
        $cells = [];

        foreach ($view['cells'] as $i => $box) {
            $id = $view['points'][$i]['id'] ?? '';
            $piece = $view['pieces'][$id] ?? null;
            $cells[] = [
                round($x + $box['x'] / $unit * $cell + $gap / 2, 2),
                round($y + $box['y'] / $unit * $cell + $gap / 2, 2),
                round($cell - $gap, 2),
                $piece === null ? null : $piece['side'],
                $piece !== null && in_array($id, $last, true),
            ];
        }

        $state = explode(':', $view['pieces']['state']['kind'] ?? '0:0:0:0');
        $panelX = $x + $boardWidth + $cell;
        $panelWidth = $x + $size - $panelX;
        $rungHeight = $size * 0.135;
        $rungGap = $size * 0.012;
        $labelWidth = $panelWidth * 0.24;
        $rungs = [];

        for ($rung = 0; $rung < BlockspaceRules::RUNGS; $rung++) {
            $name = $view['pieces']['tx'.$rung]['kind'] ?? null;
            $ry = $y + $rung * ($rungHeight + $rungGap);
            $form = [];

            if ($name !== null && isset(BlockspaceRules::FORMS[$name])) {
                $form = self::form(BlockspaceRules::FORMS[$name], $panelX + $labelWidth, $ry, $panelWidth - $labelWidth, $rungHeight);
            }

            $rungs[] = [
                'x' => round($panelX, 2), 'y' => round($ry, 2), 'w' => round($panelWidth, 2), 'h' => round($rungHeight, 2),
                'bonus' => BlockspaceRules::BONUS[$rung],
                'label' => ['x' => round($panelX + $labelWidth * 0.18, 2), 'y' => round($ry + $rungHeight * 0.62, 2), 'size' => round($rungHeight * 0.34, 2)],
                'form' => $form,
            ];
        }

        $scoreTop = $y + BlockspaceRules::RUNGS * ($rungHeight + $rungGap) + $size * 0.03;
        $swatch = $size * 0.06;
        $scores = [];

        foreach (['w' => 1, 'b' => 2] as $side => $index) {
            $sy = $scoreTop + ($index - 1) * $swatch * 1.6;
            $scores[] = ['side' => $side, 'value' => (int) ($state[$index] ?? 0), 'x' => round($panelX, 2), 'y' => round($sy, 2), 'swatch' => round($swatch, 2), 'size' => round($swatch * 0.9, 2)];
        }

        return [
            'cells' => $cells,
            'rungs' => $rungs,
            'scores' => $scores,
            'turn' => ($view['pieces']['state']['side'] ?? 'w') === 'b' ? 'b' : 'w',
            'queue' => ['value' => (int) ($state[3] ?? 0), 'x' => round($panelX, 2), 'y' => round($scoreTop + $swatch * 3.6, 2), 'size' => round($swatch * 0.5, 2)],
            'frame' => ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round($boardWidth, 2), 'h' => round($size, 2)],
        ];
    }

    /**
     * A form's cells centred in its rung's box, lying across (wider than
     * tall, as the ladder on the board page shows it).
     *
     * @param  non-empty-list<array{0: int, 1: int}>  $cells  [row, column]
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function form(array $cells, float $x, float $y, float $width, float $height): array
    {
        $rows = max(array_column($cells, 0)) + 1;
        $columns = max(array_column($cells, 1)) + 1;

        if ($rows > $columns) {
            $cells = array_map(fn (array $c): array => [$c[1], $c[0]], $cells);
            [$rows, $columns] = [$columns, $rows];
        }

        $pad = $height * 0.1;
        $mini = min(($height - 2 * $pad) / 3, ($width - 2 * $pad) / 5);
        $ox = $x + ($width - $columns * $mini) / 2;
        $oy = $y + ($height - $rows * $mini) / 2;
        $gap = $mini * 0.1;

        return array_map(fn (array $c): array => [round($ox + $c[1] * $mini + $gap / 2, 2), round($oy + $c[0] * $mini + $gap / 2, 2), round($mini - $gap, 2)], $cells);
    }
}
