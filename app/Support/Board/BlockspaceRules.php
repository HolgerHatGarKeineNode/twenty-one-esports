<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Blockspace, the league's corner-laying game of miners and a mempool, for the board game
 * core. Plain PHP, no framework. Two miners fill one block of 9 x 21 cells.
 *
 * The board: 189 cells, files a-i and ranks 1-21. White (₿) starts on e2, Black (⚡) on e20. Players see the
 * sides by the colour of their stones, Orange and Blue (GameNames::sides()); the rules keep the core's `w` and `b`.
 *
 * The mempool: 42 transactions, each of the 21 forms of one to five cells twice, are dealt in
 * a hidden order. Five stand on the ladder, oldest first. A transaction pays its size plus
 * what its rung adds: 4, 3, 2, 1, 0 from the oldest to the newest. What is taken leaves a
 * gap, everything behind it moves up a rung and the next transaction comes in as the newest.
 *
 * The rules:
 * - The sides move in the order White, Black, Black, White, again and again.
 * - A move sets one transaction from the ladder, turned and mirrored as the player likes.
 * - The first stones of a side cover its start cell. After that a side's new stones touch at
 *   least one of its own stones at a corner and none of them along an edge. Stones of the
 *   other side do not matter, but no cell holds two stones.
 * - A player may pass instead. A side with no possible move has to: either way the oldest
 *   transaction falls out and the turn goes on.
 * - The fuse (the blitz mode): at every second turn the oldest transaction falls out by
 *   itself, so waiting for a rung costs more.
 * - The game ends when the ladder is empty or after 14 passes in a row. The side with the
 *   higher fees wins, equal fees are a draw.
 *
 * Moves: `2:e2,e3,f3` sets the transaction on rung 2 (0 is the oldest) on these cells, read
 * from the top left; `pass` passes. A player's board proposes a move and legalMoves() judges it.
 *
 * The board plays with its own input (`input` = `pieces`): it takes a transaction from the
 * ladder, turns it and sets it on the board, and it shows no legal moves, as finding the
 * place is the game (user, 2026-11-04). So a snapshot carries none: up to 279 of them would
 * not fit a push (Reverb caps a message at 10 kB).
 *
 * The view: a cell per square with a point each, and two kinds of pieces no point draws, the
 * rungs `tx0` to `tx4` (kind: the form's name) and `state` (kind: `turn:white:black:queue:fuse:played:passes`,
 * the place in the turn order, both fees, the transactions still to come, the fuse and both
 * sides' first stones). The queue's order is no piece: nobody sees what comes next.
 *
 * The position is `fuse turn white black played passes pool queue deck board`: `z` with the
 * fuse and `n` without, the place in the turn order (0-3), both fees, `00` to `11` for the
 * sides that have set a stone, the passes in a row, the ladder, the queue and the whole deal
 * as forms (a = I1 … u = Z5, `-` for none), and the 189 cells row by row from a21 (`.`, `w`,
 * `b`). resources/js/blockspace/engine.js plays by the same text.
 *
 * @phpstan-type Position array{fuse: bool, ti: int, score: array{w: int, b: int}, played: array{w: bool, b: bool}, passes: int, pool: string, queue: string, deck: string, board: string}
 *
 * @implements BoardRules<Position>
 * @implements DealtGame<Position>
 */
final class BlockspaceRules implements BoardRules, DealtGame
{
    public const ROWS = 21;

    public const COLUMNS = 9;

    public const CELLS = self::ROWS * self::COLUMNS;

    /** The transactions on the ladder. */
    public const RUNGS = 5;

    /** What each rung adds to a transaction's size, the oldest first. */
    public const BONUS = [4, 3, 2, 1, 0];

    /** The side at each place of the turn order. */
    public const ORDER = ['w', 'b', 'b', 'w'];

    /** So many passes in a row end the game. */
    public const PASS_LIMIT = 14;

    /** The game mode that plays with the fuse. */
    public const FUSE_MODE = 'blitz';

    public const PASS = 'pass';

    /** The forms of one to five cells, by the letter of their place here (a = I1). */
    public const FORMS = [
        'I1' => [[0, 0]], 'I2' => [[0, 0], [0, 1]], 'I3' => [[0, 0], [0, 1], [0, 2]], 'L3' => [[0, 0], [1, 0], [1, 1]],
        'I4' => [[0, 0], [0, 1], [0, 2], [0, 3]], 'O4' => [[0, 0], [0, 1], [1, 0], [1, 1]], 'T4' => [[0, 0], [0, 1], [0, 2], [1, 1]], 'L4' => [[0, 0], [1, 0], [2, 0], [2, 1]], 'S4' => [[0, 1], [0, 2], [1, 0], [1, 1]],
        'F5' => [[0, 1], [0, 2], [1, 0], [1, 1], [2, 1]], 'I5' => [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4]], 'L5' => [[0, 0], [1, 0], [2, 0], [3, 0], [3, 1]], 'N5' => [[0, 1], [1, 1], [2, 0], [2, 1], [3, 0]], 'P5' => [[0, 0], [0, 1], [1, 0], [1, 1], [2, 0]],
        'T5' => [[0, 0], [0, 1], [0, 2], [1, 1], [2, 1]], 'U5' => [[0, 0], [0, 2], [1, 0], [1, 1], [1, 2]], 'V5' => [[0, 0], [1, 0], [2, 0], [2, 1], [2, 2]], 'W5' => [[0, 0], [1, 0], [1, 1], [2, 1], [2, 2]], 'X5' => [[0, 1], [1, 0], [1, 1], [1, 2], [2, 1]], 'Y5' => [[0, 1], [1, 0], [1, 1], [2, 1], [3, 1]], 'Z5' => [[0, 0], [0, 1], [1, 1], [2, 1], [2, 2]],
    ];

    /** The side of one cell in the view box of the board drawing. */
    private const CELL = 100;

    private const FILES = 'abcdefghi';

    private const LETTERS = 'abcdefghijklmnopqrstu';

    private const POSITION = '/^([nz]) ([0-3]) (\d{1,4}) (\d{1,4}) ([01]{2}) (\d{1,2}) ([a-u]{1,5}|-) ([a-u]{1,37}|-) ([a-u]{42}) ([.wb]{189})$/';

    private const MOVE = '/^([0-4]):([a-i]\d{1,2}(?:,[a-i]\d{1,2}){0,4})$/';

    /**
     * Every placement of every form on the board, built once per process: `cells` lists the cells of each,
     * `by` the placements that cover a cell.
     *
     * @var array<string, array{cells: list<list<int>>, by: array<int, list<int>>}>
     */
    private static array $placements = [];

    /** @var array{neighbours: list<list<int>>, diagonals: list<list<int>>}|null */
    private static ?array $around = null;

    public function start(): mixed
    {
        // The board as a picture (a lobby's or the stream's empty board): an unshuffled deal, the same every time.
        return $this->dealt(implode('', array_map(fn (string $letter): string => $letter.$letter, str_split(self::LETTERS))), false);
    }

    public function deal(string $mode): mixed
    {
        $deck = str_split(self::LETTERS.self::LETTERS);

        for ($i = count($deck) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }

        return $this->dealt(implode('', $deck), $mode === self::FUSE_MODE);
    }

    public function startOf(mixed $position): mixed
    {
        return $this->dealt($position['deck'], $position['fuse']);
    }

    public function withoutHidden(mixed $position): mixed
    {
        return [...$position, 'queue' => str_repeat('a', strlen($position['queue'])), 'deck' => str_repeat('a', strlen($position['deck']))];
    }

    public function revealsOnMove(mixed $position): bool
    {
        return $position['queue'] !== '';
    }

    /** @return Position */
    private function dealt(string $deck, bool $fuse): array
    {
        return [
            'fuse' => $fuse,
            'ti' => 0,
            'score' => ['w' => 0, 'b' => 0],
            'played' => ['w' => false, 'b' => false],
            'passes' => 0,
            'pool' => substr($deck, 0, self::RUNGS),
            'queue' => substr($deck, self::RUNGS),
            'deck' => $deck,
            'board' => str_repeat('.', self::CELLS),
        ];
    }

    public function serialize(mixed $position): string
    {
        return implode(' ', [
            $position['fuse'] ? 'z' : 'n',
            $position['ti'],
            $position['score']['w'],
            $position['score']['b'],
            ($position['played']['w'] ? '1' : '0').($position['played']['b'] ? '1' : '0'),
            $position['passes'],
            $position['pool'] === '' ? '-' : $position['pool'],
            $position['queue'] === '' ? '-' : $position['queue'],
            $position['deck'],
            $position['board'],
        ]);
    }

    public function deserialize(string $position): mixed
    {
        if (preg_match(self::POSITION, $position, $parts) !== 1) {
            throw new InvalidArgumentException("No Blockspace position: {$position}");
        }

        return [
            'fuse' => $parts[1] === 'z',
            'ti' => (int) $parts[2],
            'score' => ['w' => (int) $parts[3], 'b' => (int) $parts[4]],
            'played' => ['w' => $parts[5][0] === '1', 'b' => $parts[5][1] === '1'],
            'passes' => (int) $parts[6],
            'pool' => $parts[7] === '-' ? '' : $parts[7],
            'queue' => $parts[8] === '-' ? '' : $parts[8],
            'deck' => $parts[9],
            'board' => $parts[10],
        ];
    }

    public function turn(mixed $position): string
    {
        return self::ORDER[$position['ti']];
    }

    public function legalMoves(mixed $position): array
    {
        if ($this->isOver($position)) {
            return [];
        }

        $moves = array_map(fn (array $move): string => $move[0].':'.$this->cellNames($move[1]), $this->placementsFor($position));
        $moves[] = self::PASS;

        return $moves;
    }

    public function path(string $move): array
    {
        return preg_match(self::MOVE, $move, $parts) === 1 ? explode(',', $parts[2]) : [];
    }

    public function apply(mixed $position, string $move): mixed
    {
        if ($move === self::PASS) {
            return $this->settle($this->skipped($position));
        }

        if (preg_match(self::MOVE, $move, $parts) !== 1) {
            throw new InvalidArgumentException("No Blockspace move: {$move}");
        }

        $slot = (int) $parts[1];
        $side = $this->turn($position);
        $position['score'][$side] += $this->fee($position['pool'], $slot);
        $position['played'][$side] = true;
        $position['passes'] = 0;

        foreach (explode(',', $parts[2]) as $name) {
            $position['board'][$this->cell($name)] = $side;
        }

        $position['pool'] = substr_replace($position['pool'], '', $slot, 1);

        return $this->settle($this->nextTurn($this->refilled($position)));
    }

    public function notation(mixed $position, string $move): string
    {
        // The sign says who moved: the sides do not alternate, so a list of moves cannot tell by the place.
        $sign = $this->turn($position) === 'w' ? '₿' : '⚡';

        if (preg_match(self::MOVE, $move, $parts) !== 1) {
            // A pass has no words of its own: the record reads the same in every language.
            return $sign.' –';
        }

        $slot = (int) $parts[1];
        $cells = explode(',', $parts[2]);

        return $sign.' '.$this->name($position['pool'][$slot]).' '.$cells[0].' +'.$this->fee($position['pool'], $slot);
    }

    public function outcome(mixed $position, array $history): ?array
    {
        if (! $this->isOver($position)) {
            return null;
        }

        $reason = $position['pool'] === '' ? 'mempool_empty' : 'block_full';
        $difference = $position['score']['w'] <=> $position['score']['b'];

        return ['result' => match ($difference) {
            1 => '1-0',
            -1 => '0-1',
            default => '1/2-1/2',
        }, 'reason' => $reason];
    }

    public function reasons(): array
    {
        return [
            'mempool_empty' => 'The mempool is empty',
            'block_full' => 'No transaction fits the block any more',
        ];
    }

    public function view(mixed $position): array
    {
        $cells = [];
        $points = [];
        $pieces = [];

        for ($cell = 0; $cell < self::CELLS; $cell++) {
            $x = ($cell % self::COLUMNS) * self::CELL;
            $y = intdiv($cell, self::COLUMNS) * self::CELL;
            $id = $this->cellName($cell);
            $cells[] = ['x' => $x, 'y' => $y, 'size' => self::CELL];
            $points[] = ['id' => $id, 'x' => $x + intdiv(self::CELL, 2), 'y' => $y + intdiv(self::CELL, 2)];

            if ($position['board'][$cell] !== '.') {
                /** @var 'w'|'b' $side */
                $side = $position['board'][$cell];
                $pieces[$id] = ['side' => $side, 'kind' => 's'];
            }
        }

        foreach ($position['pool'] === '' ? [] : str_split($position['pool']) as $rung => $letter) {
            $pieces['tx'.$rung] = ['side' => 'w', 'kind' => $this->name($letter)];
        }

        $pieces['state'] = ['side' => $this->turn($position), 'kind' => implode(':', [
            $position['ti'],
            $position['score']['w'],
            $position['score']['b'],
            strlen($position['queue']),
            $position['fuse'] ? 1 : 0,
            ($position['played']['w'] ? '1' : '0').($position['played']['b'] ? '1' : '0'),
            $position['passes'],
        ])];

        return [
            'width' => self::COLUMNS * self::CELL,
            'height' => self::ROWS * self::CELL,
            'lines' => [],
            'cells' => $cells,
            'points' => $points,
            'pieces' => $pieces,
            'input' => 'pieces',
        ];
    }

    /** @param  Position  $position */
    private function isOver(array $position): bool
    {
        return $position['pool'] === '' || $position['passes'] >= self::PASS_LIMIT;
    }

    private function fee(string $pool, int $slot): int
    {
        return $this->size($pool[$slot]) + self::BONUS[$slot];
    }

    private function size(string $letter): int
    {
        return count(array_values(self::FORMS)[(int) strpos(self::LETTERS, $letter)]);
    }

    /** The form's name (`F5`) of a letter of the deal. */
    private function name(string $letter): string
    {
        return array_keys(self::FORMS)[(int) strpos(self::LETTERS, $letter)];
    }

    private function cellName(int $cell): string
    {
        return self::FILES[$cell % self::COLUMNS].(self::ROWS - intdiv($cell, self::COLUMNS));
    }

    private function cell(string $name): int
    {
        return (self::ROWS - (int) substr($name, 1)) * self::COLUMNS + (int) strpos(self::FILES, $name[0]);
    }

    /** @param  list<int>  $cells */
    private function cellNames(array $cells): string
    {
        sort($cells);

        return implode(',', array_map($this->cellName(...), $cells));
    }

    /**
     * @param  Position  $position
     * @return Position the next transaction, if one is left, as the newest on the ladder
     */
    private function refilled(array $position): array
    {
        if ($position['queue'] !== '') {
            $position['pool'] .= $position['queue'][0];
            $position['queue'] = substr($position['queue'], 1);
        }

        return $position;
    }

    /**
     * @param  Position  $position
     * @return Position without the oldest transaction
     */
    private function dropped(array $position): array
    {
        if ($position['pool'] === '') {
            return $position;
        }

        $position['pool'] = substr($position['pool'], 1);

        return $this->refilled($position);
    }

    /**
     * @param  Position  $position
     * @return Position
     */
    private function nextTurn(array $position): array
    {
        $position['ti'] = ($position['ti'] + 1) % 4;

        return $position['fuse'] && $position['ti'] % 2 === 0 ? $this->dropped($position) : $position;
    }

    /**
     * @param  Position  $position
     * @return Position after a pass
     */
    private function skipped(array $position): array
    {
        $position['passes']++;

        return $this->nextTurn($this->dropped($position));
    }

    /**
     * A side with nothing to set is passed over, until a side can move or the game is over.
     *
     * @param  Position  $position
     * @return Position
     */
    private function settle(array $position): array
    {
        while (! $this->isOver($position) && $this->placementsFor($position, true) === []) {
            $position = $this->skipped($position);
        }

        return $position;
    }

    /**
     * Every way to set a transaction of the ladder in this position, as rung and cells.
     *
     * @param  Position  $position
     * @return list<array{0: int, 1: list<int>}>
     */
    private function placementsFor(array $position, bool $first = false): array
    {
        $around = self::around();
        $board = $position['board'];
        $side = $this->turn($position);
        $edge = [];
        $corner = [];

        for ($cell = 0; $cell < self::CELLS; $cell++) {
            if ($board[$cell] === $side) {
                foreach ($around['neighbours'][$cell] as $near) {
                    $edge[$near] = true;
                }

                foreach ($around['diagonals'][$cell] as $near) {
                    $corner[$near] = true;
                }
            }
        }

        if (! $position['played'][$side]) {
            $corner[$side === 'w' ? (self::ROWS - 2) * self::COLUMNS + (self::COLUMNS >> 1) : self::COLUMNS + (self::COLUMNS >> 1)] = true;
        }

        $anchors = array_values(array_filter(array_keys($corner), fn (int $cell): bool => $board[$cell] === '.' && ! isset($edge[$cell])));
        $moves = [];

        foreach ($position['pool'] === '' ? [] : str_split($position['pool']) as $slot => $letter) {
            $placements = self::placements($letter);
            $seen = [];

            foreach ($anchors as $anchor) {
                foreach ($placements['by'][$anchor] ?? [] as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }

                    $seen[$id] = true;

                    foreach ($placements['cells'][$id] as $cell) {
                        if ($board[$cell] !== '.' || isset($edge[$cell])) {
                            continue 2;
                        }
                    }

                    $moves[] = [$slot, $placements['cells'][$id]];

                    if ($first) {
                        return $moves;
                    }
                }
            }
        }

        return $moves;
    }

    /** @return array{cells: list<list<int>>, by: array<int, list<int>>} */
    private static function placements(string $letter): array
    {
        if (isset(self::$placements[$letter])) {
            return self::$placements[$letter];
        }

        $cells = [];
        $by = [];

        foreach (self::orientations(array_values(self::FORMS)[(int) strpos(self::LETTERS, $letter)]) as $shape) {
            $height = max(0, ...array_column($shape, 0)) + 1;
            $width = max(0, ...array_column($shape, 1)) + 1;

            for ($row = 0; $row <= self::ROWS - $height; $row++) {
                for ($column = 0; $column <= self::COLUMNS - $width; $column++) {
                    $placed = array_map(fn (array $part): int => ($row + $part[0]) * self::COLUMNS + $column + $part[1], $shape);

                    foreach ($placed as $cell) {
                        $by[$cell][] = count($cells);
                    }

                    $cells[] = $placed;
                }
            }
        }

        return self::$placements[$letter] = ['cells' => $cells, 'by' => $by];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $shape
     * @return list<list<array{0: int, 1: int}>> every way this form can lie, each once
     */
    private static function orientations(array $shape): array
    {
        $normal = function (array $parts): array {
            $top = min(PHP_INT_MAX, ...array_column($parts, 0));
            $left = min(PHP_INT_MAX, ...array_column($parts, 1));
            $parts = array_map(fn (array $part): array => [$part[0] - $top, $part[1] - $left], $parts);
            sort($parts);

            return $parts;
        };
        $found = [];

        foreach ([1, -1] as $mirror) {
            $turned = array_map(fn (array $part): array => [$part[0], $part[1] * $mirror], $shape);

            for ($quarter = 0; $quarter < 4; $quarter++) {
                $turned = $normal(array_map(fn (array $part): array => [$part[1], -$part[0]], $turned));
                $found[json_encode($turned)] = $turned;
            }
        }

        return array_values($found);
    }

    /** @return array{neighbours: list<list<int>>, diagonals: list<list<int>>} */
    private static function around(): array
    {
        if (self::$around !== null) {
            return self::$around;
        }

        $near = function (int $cell, array $steps): array {
            $row = intdiv($cell, self::COLUMNS);
            $column = $cell % self::COLUMNS;
            $cells = [];

            foreach ($steps as [$down, $right]) {
                if ($row + $down >= 0 && $row + $down < self::ROWS && $column + $right >= 0 && $column + $right < self::COLUMNS) {
                    $cells[] = ($row + $down) * self::COLUMNS + $column + $right;
                }
            }

            return $cells;
        };

        $all = range(0, self::CELLS - 1);

        return self::$around = [
            'neighbours' => array_map(fn (int $cell): array => $near($cell, [[1, 0], [-1, 0], [0, 1], [0, -1]]), $all),
            'diagonals' => array_map(fn (int $cell): array => $near($cell, [[1, 1], [1, -1], [-1, 1], [-1, -1]]), $all),
        ];
    }
}
