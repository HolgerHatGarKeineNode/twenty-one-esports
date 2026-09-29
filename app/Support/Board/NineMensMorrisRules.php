<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Nine men's morris ("Mühle", plan "Mühle und Dame", P3): the classic game on
 * three nested squares, plain PHP without the framework.
 *
 * Board: 24 points in the standard notation, files a-g and ranks 1-7 with a1
 * bottom left (outer square a1 a7 g7 g1, middle b2 b6 f6 f2, inner c3 c5 e5 e3,
 * joined in the middle of each side). Sixteen lines of three points; the
 * points next to each other on a line are neighbours.
 *
 * Rules, as played here:
 * - Each side has nine men and places them one by one on empty points
 *   (White first); after all 18 are placed, a turn moves one man along a
 *   line to a neighbouring empty point.
 * - A side down to three men flies: one man to any empty point.
 * - Three own men on one line are a mill. Closing a mill (by placing, moving
 *   or flying) removes one man of the other side; a man in a mill only when
 *   all of that side's men on the board stand in mills. Closing two mills
 *   with one man removes one man, not two.
 * - A side left with fewer than three men (on the board and in hand) loses;
 *   so does a side that has no move when it is its turn.
 * - Drawn: the same position (board, side to move, men in hand) a third
 *   time, or QUIET_PLY_LIMIT moves in a row without a mill.
 *
 * Sources, read 2026-09-29: German Wikipedia "Mühle (Spiel)", section
 * "Spielablauf" (placing, moving, flying at three men, loss when blocked or
 * after the next man is taken; "Die offiziellen Turnierregeln erlauben seit
 * 2010 das Schlagen eines Steines aus einer geschlossenen Mühle, wenn der
 * Gegner nur noch Steine in geschlossenen Mühlen hat" — the rules of the
 * Welt-Mühlespiel-Dachverband, whose own site muehlespiel.eu no longer serves
 * them); English Wikipedia "Nine men's morris", section "Rules" (a man in a
 * mill "can be removed only if no other pieces are available", loss at two
 * men); mathematische-basteleien.de/muehle.htm, "Zwei Sonderfälle" (two mills
 * closed at once: "trotzdem nur einen Stein nehmen"). The draw after 50 moves
 * each without a mill has no primary source for morris (unbelegt): the number
 * follows the chess fifty-move rule (FIDE Laws of Chess, 9.3) so a game
 * without a clock cannot run forever (plan risk "Remis-Schleifen").
 *
 * Moves: a placement is the point (`d7`), a move or flight is `from-to`
 * (`a1-a4`); a move that closes a mill carries the removed man after `x`
 * (`d7xa1`, `a1-a4xg7`). A move that closes a mill is legal only with a
 * removal (the removal is part of the move, not a second turn), so the path
 * of a move — the points clicked in order — is never the beginning of
 * another: whether `a1-a4` closes a mill depends only on the position. When
 * the other side has no man on the board to remove, the mill removes nothing.
 *
 * The position is `board turn whiteInHand blackInHand quietPlies`: 24
 * characters (`.`, `w`, `b`) in the order of POINTS, e.g. the start
 * `........................ w 9 9 0`.
 *
 * @phpstan-type Position array{board: array<string, 'w'|'b'|null>, turn: 'w'|'b', hand: array{w: int, b: int}, quiet: int}
 *
 * @implements BoardRules<Position>
 */
final class NineMensMorrisRules implements BoardRules
{
    public const MEN = 9;

    /** A side with this many men (and none in hand) flies. */
    public const FLYING_MEN = 3;

    /** 50 moves each without a mill draw the game (see the class comment: unbelegt, after chess). */
    public const QUIET_PLY_LIMIT = 100;

    /** The same position this often draws the game. */
    public const REPETITIONS = 3;

    public const POINTS = [
        'a1', 'a4', 'a7', 'b2', 'b4', 'b6', 'c3', 'c4', 'c5', 'd1', 'd2', 'd3',
        'd5', 'd6', 'd7', 'e3', 'e4', 'e5', 'f2', 'f4', 'f6', 'g1', 'g4', 'g7',
    ];

    public const LINES = [
        // Rows, top to bottom.
        ['a7', 'd7', 'g7'], ['b6', 'd6', 'f6'], ['c5', 'd5', 'e5'], ['a4', 'b4', 'c4'],
        ['e4', 'f4', 'g4'], ['c3', 'd3', 'e3'], ['b2', 'd2', 'f2'], ['a1', 'd1', 'g1'],
        // Columns, left to right.
        ['a1', 'a4', 'a7'], ['b2', 'b4', 'b6'], ['c3', 'c4', 'c5'], ['d1', 'd2', 'd3'],
        ['d5', 'd6', 'd7'], ['e3', 'e4', 'e5'], ['f2', 'f4', 'f6'], ['g1', 'g4', 'g7'],
    ];

    private const MOVE = '/^([a-g][1-7])(?:-([a-g][1-7]))?(?:x([a-g][1-7]))?$/';

    public function start(): mixed
    {
        return ['board' => array_fill_keys(self::POINTS, null), 'turn' => 'w', 'hand' => ['w' => self::MEN, 'b' => self::MEN], 'quiet' => 0];
    }

    public function serialize(mixed $position): string
    {
        return implode('', array_map(fn (string $point): string => $position['board'][$point] ?? '.', self::POINTS))
            ." {$position['turn']} {$position['hand']['w']} {$position['hand']['b']} {$position['quiet']}";
    }

    public function deserialize(string $position): mixed
    {
        if (preg_match('/^([.wb]{24}) ([wb]) (\d) (\d) (\d{1,3})$/', $position, $parts) !== 1) {
            throw new InvalidArgumentException("No nine men's morris position: {$position}");
        }

        $board = [];

        foreach (self::POINTS as $index => $point) {
            $board[$point] = match ($parts[1][$index]) {
                'w' => 'w',
                'b' => 'b',
                default => null,
            };
        }

        $hand = ['w' => (int) $parts[3], 'b' => (int) $parts[4]];

        foreach (['w', 'b'] as $side) {
            if ($hand[$side] + self::count($board, $side) > self::MEN) {
                throw new InvalidArgumentException("More than nine men of one side: {$position}");
            }
        }

        return ['board' => $board, 'turn' => $parts[2] === 'b' ? 'b' : 'w', 'hand' => $hand, 'quiet' => (int) $parts[5]];
    }

    public function turn(mixed $position): string
    {
        return $position['turn'];
    }

    public function legalMoves(mixed $position): array
    {
        $side = $position['turn'];
        $board = $position['board'];

        if (self::men($position, $side) < self::FLYING_MEN) {
            return [];
        }

        $empty = array_keys(array_filter($board, fn (?string $piece): bool => $piece === null));
        $steps = [];

        if ($position['hand'][$side] > 0) {
            foreach ($empty as $to) {
                $steps[] = [null, $to];
            }
        } else {
            $flying = self::count($board, $side) === self::FLYING_MEN;

            foreach ($board as $from => $piece) {
                if ($piece !== $side) {
                    continue;
                }

                foreach ($flying ? $empty : self::neighbours($from) as $to) {
                    if ($board[$to] === null) {
                        $steps[] = [$from, $to];
                    }
                }
            }
        }

        $moves = [];

        foreach ($steps as [$from, $to]) {
            $move = $from === null ? $to : "{$from}-{$to}";
            $after = $board;

            if ($from !== null) {
                $after[$from] = null;
            }

            $after[$to] = $side;
            $removable = self::inMill($after, $to) ? self::removable($after, self::other($side)) : [];

            if ($removable === []) {
                $moves[] = $move;

                continue;
            }

            foreach ($removable as $point) {
                $moves[] = "{$move}x{$point}";
            }
        }

        return $moves;
    }

    public function path(string $move): array
    {
        return preg_split('/[-x]/', $move) ?: [];
    }

    public function apply(mixed $position, string $move): mixed
    {
        if (preg_match(self::MOVE, $move, $parts) !== 1) {
            throw new InvalidArgumentException("No nine men's morris move: {$move}");
        }

        $side = $position['turn'];
        $to = ($parts[2] ?? '') !== '' ? $parts[2] : $parts[1];

        if ($to === $parts[1]) {
            $position['hand'][$side]--;
        } else {
            $position['board'][$parts[1]] = null;
        }

        $position['board'][$to] = $side;

        if (($parts[3] ?? '') !== '') {
            $position['board'][$parts[3]] = null;
        }

        $position['quiet'] = self::inMill($position['board'], $to) ? 0 : $position['quiet'] + 1;
        $position['turn'] = self::other($side);

        return $position;
    }

    public function notation(mixed $position, string $move): string
    {
        return $move;
    }

    public function outcome(mixed $position, array $history): ?array
    {
        $toMove = $position['turn'];
        $won = $toMove === 'w' ? '0-1' : '1-0';

        if (self::men($position, $toMove) < self::FLYING_MEN) {
            return ['result' => $won, 'reason' => 'two_men'];
        }

        if ($this->legalMoves($position) === []) {
            return ['result' => $won, 'reason' => 'blocked'];
        }

        $key = self::repetitionKey($this->serialize($position));
        $seen = count(array_filter($history, fn (string $earlier): bool => self::repetitionKey($earlier) === $key));

        if ($seen >= self::REPETITIONS) {
            return ['result' => '1/2-1/2', 'reason' => 'repetition'];
        }

        if ($position['quiet'] >= self::QUIET_PLY_LIMIT) {
            return ['result' => '1/2-1/2', 'reason' => 'no_mill'];
        }

        return null;
    }

    public function reasons(): array
    {
        return [
            'two_men' => 'Down to two men',
            'blocked' => 'No move left',
            'repetition' => 'Threefold repetition',
            'no_mill' => 'Fifty moves each without a mill',
        ];
    }

    public function view(mixed $position): array
    {
        $lines = [];

        foreach (self::LINES as $line) {
            [$x1, $y1] = self::at($line[0]);
            [$x2, $y2] = self::at($line[2]);
            $lines[] = [$x1, $y1, $x2, $y2];
        }

        $pieces = [];

        foreach ($position['board'] as $point => $piece) {
            if ($piece !== null) {
                $pieces[$point] = ['side' => $piece, 'kind' => 'man'];
            }
        }

        return [
            'width' => 700,
            'height' => 700,
            'lines' => $lines,
            'cells' => [],
            'points' => array_map(fn (string $point): array => ['id' => $point, 'x' => self::at($point)[0], 'y' => self::at($point)[1]], self::POINTS),
            'pieces' => $pieces,
        ];
    }

    /**
     * Points joined to this one by a line segment.
     *
     * @return list<string>
     */
    public static function neighbours(string $point): array
    {
        $neighbours = [];

        foreach (self::LINES as $line) {
            $index = array_search($point, $line, true);

            if ($index === false) {
                continue;
            }

            foreach ([$index - 1, $index + 1] as $next) {
                if (isset($line[$next])) {
                    $neighbours[] = $line[$next];
                }
            }
        }

        return $neighbours;
    }

    /**
     * Whether the man on this point stands in a mill (after a move to it:
     * whether the move closed one).
     *
     * @param  array<string, 'w'|'b'|null>  $board
     */
    public static function inMill(array $board, string $point): bool
    {
        $side = $board[$point] ?? null;

        if ($side === null) {
            return false;
        }

        foreach (self::LINES as $line) {
            if (in_array($point, $line, true) && array_all($line, fn (string $on): bool => $board[$on] === $side)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The men of this side a mill may remove: those outside a mill, or any
     * when all stand in mills.
     *
     * @param  array<string, 'w'|'b'|null>  $board
     * @param  'w'|'b'  $side
     * @return list<string>
     */
    private static function removable(array $board, string $side): array
    {
        $men = array_keys(array_filter($board, fn (?string $piece): bool => $piece === $side));
        $free = array_values(array_filter($men, fn (string $point): bool => ! self::inMill($board, $point)));

        return $free !== [] ? $free : $men;
    }

    /**
     * @param  Position  $position
     * @param  'w'|'b'  $side
     */
    private static function men(array $position, string $side): int
    {
        return self::count($position['board'], $side) + $position['hand'][$side];
    }

    /**
     * @param  array<string, 'w'|'b'|null>  $board
     */
    private static function count(array $board, string $side): int
    {
        return count(array_filter($board, fn (?string $piece): bool => $piece === $side));
    }

    /**
     * @param  'w'|'b'  $side
     * @return 'w'|'b'
     */
    private static function other(string $side): string
    {
        return $side === 'w' ? 'b' : 'w';
    }

    /**
     * A serialized position without its quiet-move count: two positions
     * repeat when board, side to move and men in hand are the same.
     */
    private static function repetitionKey(string $serialized): string
    {
        return substr($serialized, 0, (int) strrpos($serialized, ' '));
    }

    /**
     * SVG units of a point: 100 per step, a1 bottom left.
     *
     * @return array{0: int, 1: int}
     */
    private static function at(string $point): array
    {
        return [50 + 100 * (ord($point[0]) - ord('a')), 650 - 100 * ((int) $point[1] - 1)];
    }
}
