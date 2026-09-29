<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Checkers ("Dame") by German rules on 8 x 8 (plan "Mühle und Dame", P4), for
 * the board game core. Plain PHP, no framework.
 *
 * The board: the 32 dark squares of a chess board, named as in chess (a1 is
 * dark and in White's bottom left corner). White starts on ranks 1-3 and
 * moves first, Black on ranks 6-8.
 *
 * The rules (sources and the chosen variants: the plan, "Aktuell gültig"):
 * - A man steps one square diagonally forward; a king ("Dame") moves any
 *   distance along a diagonal over empty squares (flying king).
 * - Capturing is compulsory: while any capture exists, only captures are
 *   legal. Men capture forward and backward.
 * - A capture chain goes on as long as the capturing piece can capture
 *   again; the move is only complete when it cannot. Among several chains
 *   the player chooses freely: no majority capture, no precedence of kings.
 * - A king captures a single enemy piece any distance away on its diagonal
 *   and lands on the square directly behind it.
 * - Captured pieces are removed after the move: until then they block, and
 *   no piece is jumped twice.
 * - A man that reaches the far rank becomes a king, also by a capture; that
 *   ends the move, even if it could capture on as a man.
 * - A side without a move (no pieces left, or all blocked) loses.
 * - Draw: the same position with the same side to move for the third time,
 *   or 25 moves of each side (50 plies) with only kings moving and nothing
 *   captured. Men only move forward and pieces only leave, so every game ends.
 *
 * Moves: `c3-d4` a step, `c3xe5xc7` a capture chain (every landing square).
 * The path to click is the squares in that order. The landing squares name
 * a chain completely: the captured piece of each jump stands right before
 * its landing square. As a chain must go on while it can, no legal move's
 * path is the beginning of another's.
 *
 * The position is `squares turn quiet`: one character per dark square in
 * the order a1 c1 e1 g1 b2 d2 … h8 (`.` empty, `w`/`b` a man, `W`/`B` a
 * king), the side to move, and the plies since the last capture or man move.
 *
 * @phpstan-type Position array{board: array<string, 'w'|'b'|'W'|'B'|null>, turn: 'w'|'b', quiet: int}
 *
 * @implements BoardRules<Position>
 */
final class CheckersRules implements BoardRules
{
    /** 25 moves of each side with only kings moving and no capture draw the game. */
    public const QUIET_PLY_LIMIT = 50;

    /** How often the same position with the same side to move draws the game. */
    public const REPETITIONS = 3;

    /** The side of one square in the view box of the board drawing. */
    private const CELL = 100;

    private const DIRECTIONS = [[1, 1], [-1, 1], [1, -1], [-1, -1]];

    private const FILES = 'abcdefgh';

    /** @var list<string>|null */
    private static ?array $squares = null;

    /**
     * The 32 dark squares, rank 1 first, each rank from file a.
     *
     * @return list<string>
     */
    public static function squares(): array
    {
        if (self::$squares === null) {
            self::$squares = [];

            for ($rank = 1; $rank <= 8; $rank++) {
                for ($file = 0; $file < 8; $file++) {
                    if (($file + $rank) % 2 === 1) {
                        self::$squares[] = self::FILES[$file].$rank;
                    }
                }
            }
        }

        return self::$squares;
    }

    public function start(): mixed
    {
        $board = [];

        foreach (self::squares() as $square) {
            $rank = (int) $square[1];
            $board[$square] = match (true) {
                $rank <= 3 => 'w',
                $rank >= 6 => 'b',
                default => null,
            };
        }

        return ['board' => $board, 'turn' => 'w', 'quiet' => 0];
    }

    public function serialize(mixed $position): string
    {
        return implode('', array_map(fn (string $square): string => $position['board'][$square] ?? '.', self::squares()))
            .' '.$position['turn'].' '.$position['quiet'];
    }

    public function deserialize(string $position): mixed
    {
        if (preg_match('/^([.wbWB]{32}) ([wb]) (\d{1,4})$/', $position, $parts) !== 1) {
            throw new InvalidArgumentException("No checkers position: {$position}");
        }

        $board = [];

        foreach (self::squares() as $index => $square) {
            $board[$square] = match ($parts[1][$index]) {
                'w' => 'w',
                'b' => 'b',
                'W' => 'W',
                'B' => 'B',
                default => null,
            };
        }

        return ['board' => $board, 'turn' => $parts[2] === 'b' ? 'b' : 'w', 'quiet' => (int) $parts[3]];
    }

    public function turn(mixed $position): string
    {
        return $position['turn'];
    }

    public function legalMoves(mixed $position): array
    {
        $side = $position['turn'];
        $captures = [];

        foreach ($position['board'] as $square => $piece) {
            if ($piece !== null && strtolower($piece) === $side) {
                $board = $position['board'];
                $board[$square] = null;
                $this->captureChains($board, $side, $piece !== $side, $square, [], [$square], $captures);
            }
        }

        // Capturing is compulsory: any capture rules out every step.
        if ($captures !== []) {
            return array_values(array_unique(array_map(fn (array $path): string => implode('x', $path), $captures)));
        }

        $steps = [];

        foreach ($position['board'] as $square => $piece) {
            if ($piece === null || strtolower($piece) !== $side) {
                continue;
            }

            $king = $piece !== $side;

            foreach (self::DIRECTIONS as [$df, $dr]) {
                if (! $king && $dr !== self::forward($side)) {
                    continue;
                }

                $to = self::offset($square, $df, $dr);

                while ($to !== null && $position['board'][$to] === null) {
                    $steps[] = $square.'-'.$to;
                    $to = $king ? self::offset($to, $df, $dr) : null;
                }
            }
        }

        return $steps;
    }

    public function path(string $move): array
    {
        return preg_split('/[-x]/', $move) ?: [];
    }

    public function apply(mixed $position, string $move): mixed
    {
        $path = $this->path($move);
        $from = $path[0];
        $to = $path[count($path) - 1];
        $piece = $position['board'][$from];
        $side = $position['turn'];
        $capture = str_contains($move, 'x');

        if ($capture) {
            // The captured piece of each jump stands right before its landing square.
            for ($i = 1; $i < count($path); $i++) {
                [$df, $dr] = self::direction($path[$i - 1], $path[$i]);
                $position['board'][(string) self::offset($path[$i], -$df, -$dr)] = null;
            }
        }

        $man = $piece === $side;
        $position['board'][$from] = null;
        $position['board'][$to] = $man && (int) $to[1] === self::farRank($side) ? strtoupper($side) : $piece;
        // Only king moves without a capture count towards the draw.
        $position['quiet'] = $capture || $man ? 0 : $position['quiet'] + 1;
        $position['turn'] = $side === 'w' ? 'b' : 'w';

        return $position;
    }

    public function notation(mixed $position, string $move): string
    {
        return $move;
    }

    public function outcome(mixed $position, array $history): ?array
    {
        $side = $position['turn'];

        if ($this->legalMoves($position) === []) {
            $left = array_filter($position['board'], fn (?string $piece): bool => $piece !== null && strtolower($piece) === $side);

            return ['result' => $side === 'w' ? '0-1' : '1-0', 'reason' => $left === [] ? 'no_pieces' : 'no_moves'];
        }

        if ($position['quiet'] >= self::QUIET_PLY_LIMIT) {
            return ['result' => '1/2-1/2', 'reason' => 'no_progress'];
        }

        $now = self::placement($this->serialize($position));
        $seen = count(array_filter($history, fn (string $earlier): bool => self::placement($earlier) === $now));

        if ($seen >= self::REPETITIONS) {
            return ['result' => '1/2-1/2', 'reason' => 'repetition'];
        }

        return null;
    }

    public function reasons(): array
    {
        return [
            'no_pieces' => 'No pieces left',
            'no_moves' => 'No move left',
            'repetition' => 'Same position three times',
            'no_progress' => '25 moves without a capture or a man moving',
        ];
    }

    public function view(mixed $position): array
    {
        $cells = [];
        $points = [];
        $pieces = [];

        foreach (self::squares() as $square) {
            $x = (ord($square[0]) - ord('a')) * self::CELL;
            $y = (8 - (int) $square[1]) * self::CELL;
            $cells[] = ['x' => $x, 'y' => $y, 'size' => self::CELL];
            $points[] = ['id' => $square, 'x' => $x + intdiv(self::CELL, 2), 'y' => $y + intdiv(self::CELL, 2)];
            $piece = $position['board'][$square];

            if ($piece !== null) {
                $pieces[$square] = ['side' => strtolower($piece) === 'w' ? 'w' : 'b', 'kind' => ctype_upper($piece) ? 'king' : 'man'];
            }
        }

        return [
            'width' => 8 * self::CELL,
            'height' => 8 * self::CELL,
            'lines' => [],
            'cells' => $cells,
            'points' => $points,
            'pieces' => $pieces,
        ];
    }

    /**
     * Collects every complete capture chain of the piece now on $square.
     * $board has the capturing piece lifted off its start square; captured
     * pieces stay on it (they are removed after the move) and are listed in
     * $captured, so none is jumped twice and each still blocks.
     *
     * @param  array<string, 'w'|'b'|'W'|'B'|null>  $board
     * @param  list<string>  $captured
     * @param  list<string>  $path
     * @param  list<list<string>>  $chains
     */
    private function captureChains(array $board, string $side, bool $king, string $square, array $captured, array $path, array &$chains): void
    {
        $jumped = false;

        foreach (self::DIRECTIONS as [$df, $dr]) {
            $over = self::offset($square, $df, $dr);

            // A king looks along the diagonal past empty squares; a man only at its neighbour.
            while ($king && $over !== null && $board[$over] === null) {
                $over = self::offset($over, $df, $dr);
            }

            if ($over === null || $board[$over] === null || strtolower($board[$over]) === $side || in_array($over, $captured, true)) {
                continue;
            }

            $land = self::offset($over, $df, $dr);

            if ($land === null || $board[$land] !== null) {
                continue;
            }

            $jumped = true;
            $next = [...$path, $land];

            // A man that reaches the far rank is crowned and the move ends there.
            if (! $king && (int) $land[1] === self::farRank($side)) {
                $chains[] = $next;

                continue;
            }

            $this->captureChains($board, $side, $king, $land, [...$captured, $over], $next, $chains);
        }

        if (! $jumped && count($path) > 1) {
            $chains[] = $path;
        }
    }

    /** The rank a man of this side moves towards. */
    private static function forward(string $side): int
    {
        return $side === 'w' ? 1 : -1;
    }

    private static function farRank(string $side): int
    {
        return $side === 'w' ? 8 : 1;
    }

    private static function offset(string $square, int $df, int $dr): ?string
    {
        $file = ord($square[0]) - ord('a') + $df;
        $rank = (int) $square[1] + $dr;

        return $file >= 0 && $file < 8 && $rank >= 1 && $rank <= 8 ? self::FILES[$file].$rank : null;
    }

    /**
     * The unit step from one square to another on the same diagonal.
     *
     * @return array{0: int, 1: int}
     */
    private static function direction(string $from, string $to): array
    {
        return [ord($to[0]) <=> ord($from[0]), (int) $to[1] <=> (int) $from[1]];
    }

    /** The squares and the side to move of a serialized position, without its counter. */
    private static function placement(string $serialized): string
    {
        return substr($serialized, 0, 34);
    }
}
