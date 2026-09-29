<?php

namespace Tests\Support;

use App\Support\Board\BoardRules;
use InvalidArgumentException;

/**
 * A tiny board game that proves the board game core end to end (plan
 * "Mühle und Dame", P2) before nine men's morris (P3) and checkers (P4) exist:
 * three men's morris. Test-only.
 *
 * A 3 x 3 grid of points a1..c3 (files a-c, ranks 1-3, rank 1 at the bottom),
 * joined along the rows, the columns and the two diagonals. Each side first
 * places its three men on empty points (move `b2`), then steps a man to an
 * adjacent empty point (move `a1-b2`). Three in a line wins; the game is
 * drawn after MOVE_LIMIT moves. (Three men each can never wall in the other
 * side's three, so there is no "no move left" rule to test here; nine men's
 * morris brings one in P3.)
 *
 * The position is `board turn`: nine characters for a1, b1, c1, a2 … c3
 * (`.`, `w`, `b`) and the side to move, e.g. `w...b.... w`.
 *
 * @implements BoardRules<array{board: array<string, 'w'|'b'|null>, turn: 'w'|'b'}>
 */
final class FixtureBoardRules implements BoardRules
{
    public const MOVE_LIMIT = 40;

    public const POINTS = ['a1', 'b1', 'c1', 'a2', 'b2', 'c2', 'a3', 'b3', 'c3'];

    public const LINES = [
        ['a1', 'b1', 'c1'], ['a2', 'b2', 'c2'], ['a3', 'b3', 'c3'],
        ['a1', 'a2', 'a3'], ['b1', 'b2', 'b3'], ['c1', 'c2', 'c3'],
        ['a1', 'b2', 'c3'], ['a3', 'b2', 'c1'],
    ];

    public function start(): mixed
    {
        return ['board' => array_fill_keys(self::POINTS, null), 'turn' => 'w'];
    }

    public function serialize(mixed $position): string
    {
        return implode('', array_map(fn (string $point): string => $position['board'][$point] ?? '.', self::POINTS)).' '.$position['turn'];
    }

    public function deserialize(string $position): mixed
    {
        if (preg_match('/^([.wb]{9}) ([wb])$/', $position, $parts) !== 1) {
            throw new InvalidArgumentException("No fixture board position: {$position}");
        }

        $board = [];

        foreach (self::POINTS as $index => $point) {
            $board[$point] = match ($parts[1][$index]) {
                'w' => 'w',
                'b' => 'b',
                default => null,
            };
        }

        return ['board' => $board, 'turn' => $parts[2] === 'b' ? 'b' : 'w'];
    }

    public function turn(mixed $position): string
    {
        return $position['turn'];
    }

    public function legalMoves(mixed $position): array
    {
        $side = $position['turn'];
        $empty = array_keys(array_filter($position['board'], fn (?string $piece): bool => $piece === null));

        if (count(array_filter($position['board'], fn (?string $piece): bool => $piece === $side)) < 3) {
            return array_values($empty);
        }

        $moves = [];

        foreach ($position['board'] as $point => $piece) {
            if ($piece !== $side) {
                continue;
            }

            foreach (self::neighbours($point) as $to) {
                if ($position['board'][$to] === null) {
                    $moves[] = $point.'-'.$to;
                }
            }
        }

        return $moves;
    }

    public function path(string $move): array
    {
        return explode('-', $move);
    }

    public function apply(mixed $position, string $move): mixed
    {
        $path = $this->path($move);
        $side = $position['turn'];

        if (count($path) === 2) {
            $position['board'][$path[0]] = null;
        }

        $position['board'][$path[count($path) - 1]] = $side;
        $position['turn'] = $side === 'w' ? 'b' : 'w';

        return $position;
    }

    public function notation(mixed $position, string $move): string
    {
        return $move;
    }

    public function outcome(mixed $position, array $history): ?array
    {
        $mover = $position['turn'] === 'w' ? 'b' : 'w';

        foreach (self::LINES as $line) {
            if (array_all($line, fn (string $point): bool => $position['board'][$point] === $mover)) {
                return ['result' => $mover === 'w' ? '1-0' : '0-1', 'reason' => 'three_in_a_row'];
            }
        }

        if (count($history) - 1 >= self::MOVE_LIMIT) {
            return ['result' => '1/2-1/2', 'reason' => 'move_limit'];
        }

        return null;
    }

    public function reasons(): array
    {
        return ['three_in_a_row' => 'Three in a row', 'move_limit' => 'Move limit'];
    }

    public function view(mixed $position): array
    {
        $at = fn (string $point): array => [50 + 100 * (ord($point[0]) - ord('a')), 250 - 100 * ((int) $point[1] - 1)];
        $lines = [];

        foreach (self::LINES as $line) {
            [$x1, $y1] = $at($line[0]);
            [$x2, $y2] = $at($line[2]);
            $lines[] = [$x1, $y1, $x2, $y2];
        }

        $pieces = [];

        foreach ($position['board'] as $point => $piece) {
            if ($piece !== null) {
                $pieces[$point] = ['side' => $piece, 'kind' => 'man'];
            }
        }

        return [
            'width' => 300,
            'height' => 300,
            'lines' => $lines,
            'cells' => [],
            'points' => array_map(fn (string $point): array => ['id' => $point, 'x' => $at($point)[0], 'y' => $at($point)[1]], self::POINTS),
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

        return array_values(array_unique($neighbours));
    }
}
