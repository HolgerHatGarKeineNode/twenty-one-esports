<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Blockli, a race with blocks on 9 x 9 (Quoridor-type rules), for the board
 * game core. Plain PHP, no framework.
 *
 * The board: 81 squares named as in chess, files a-i and ranks 1-9. White
 * (the ₿ pawn) starts on e1 and wins on rank 9, Black (the ⚡ pawn) starts
 * on e9 and wins on rank 1. White moves first. Each side has 10 blocks.
 *
 * The rules:
 * - A move is either a pawn move or a block.
 * - A pawn steps one square up, down, left or right, never through a block
 *   and never off the board.
 * - Facing the other pawn, a pawn jumps straight over it. If a block or the
 *   edge of the board stands right behind the other pawn, it steps to a
 *   square beside the other pawn instead (either side, unless a block or
 *   the edge is in the way).
 * - A block is two squares long and lies in the groove between squares. It
 *   may not overlap another block, may not cross one at its middle, and may
 *   not leave either pawn without a way to its goal rank. Pawns are no
 *   obstacle for that way.
 * - There is no pass, as a pawn is never stuck: it could only be with the
 *   other pawn on its one open neighbouring square and nothing open beyond
 *   that square. Those two squares would then be all that either pawn can
 *   reach, so each pawn's goal rank would have to run through the other
 *   pawn's square (its own is off it, or the game were over): two
 *   neighbouring squares, while ranks 1 and 9 lie eight rows apart.
 * - The first pawn on its goal rank wins.
 * - Draw: the same position with the same side to move for the 21st time
 *   (REPETITIONS; DerCaddy, 2026-10-09: a pawn cannot pass, so moving to and
 *   fro to wait is play, and three times ended such waits too early; the
 *   board warns one time before, see `repetitions` in the view), or 100
 *   moves of each side (200 plies) without a new block. After the last block
 *   a pawn that heads for its goal arrives long before that, so the limit
 *   only ends games that nobody tries to win.
 * - The race standing (standing()) counts, for every position, each side's
 *   steps to its goal rank and blocks left, a block worth BLOCK_STEPS steps:
 *   who would be ahead. The board shows it; a tournament could decide a
 *   drawn game by it.
 *
 * Sources, read 2026-09-30: English Wikipedia "Quoridor", sections on the
 * rules (the jump; beside the other pawn when a wall or the edge stands
 * behind it; walls are not jumped, sideways neither) and on the notation
 * (squares a1-i9 from the first player's side, a pawn move by its target
 * square, a wall by the square nearest to a1 plus h or v). The published
 * rules have no draw (unbelegt): repetition and the move limit follow the
 * other games of the board game core, so that no game can run forever.
 *
 * Moves: `e1-e2` moves the pawn from e1 to e2 (a step or a jump); `e3h` and
 * `e3v` set a horizontal or vertical block whose middle is the top right
 * corner of e3, so `e3h` lies above e3 and f3 and `e3v` right of e3 and e4.
 * The notation drops the start square of a pawn move (`e2`), as the
 * algebraic Quoridor notation does; a block is written as it is played.
 *
 * The board plays Blockli with its block input (`input` = `blocks` in the
 * view), as the Blockli prototype does: one tap on a target square moves the
 * pawn; for a block the board shows it at the crossing nearest to a tap
 * (direction by where the tap lies), and a second tap or a button sets it.
 * So the path of a pawn move is its start and target square, and the path
 * of a block its crossing (named after the two squares it lies between,
 * `e3/f4`) and direction, `h` or `v`. Every path has two parts and none
 * repeats, so no path is the beginning of another's.
 *
 * The drawing (view): the 81 squares as cells with a point each, the 64
 * crossings and a tray of ten points per side for the blocks it has left
 * (Black's above the board, White's below). Pieces: `pawn`; a block on its
 * crossing as `block-h` or `block-v`, a bar across two squares and the
 * groove between them; a block in the tray as `spare`. `repetitions` is the
 * repetition limit: the pieces and the side to move are the whole position,
 * so the board counts how often it has stood there and warns at the last
 * time before the draw.
 *
 * The position is `white black left-w left-b turn blocks quiet`: the squares
 * of both pawns, the blocks each side has left, the side to move, the blocks
 * on the board (`-`, or a sorted comma list of block and setter such as
 * `d6vb,e3hw`) and the plies since the last block.
 *
 * @phpstan-type Side 'w'|'b'
 * @phpstan-type Blocks array<int, 'w'|'b'>
 * @phpstan-type Position array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}
 *
 * @implements BoardRules<Position>
 * @implements RaceStanding<Position>
 */
final class BlockliRules implements BoardRules, RaceStanding
{
    /** The blocks each side starts with. */
    public const BLOCKS = 10;

    /** How often the same position with the same side to move draws the game (the board warns one time before). */
    public const REPETITIONS = 21;

    /**
     * What a block in hand is worth in steps, for the race standing (standing()). Measured, not chosen: in
     * 1,000 self-play games of the Blockli engine on two levels (DerCaddy's prototype, 2026-10-09), a logistic
     * fit of the winner on the step and block differences gave 1.58 steps a block on both; rounded to a half
     * for a rule players can count. The standing then names the later winner in about three positions of four.
     */
    public const BLOCK_STEPS = 1.5;

    /** 100 moves of each side without a new block draw the game. */
    public const QUIET_PLY_LIMIT = 200;

    /** The side of one square and the width of a groove in the view box of the board drawing. */
    private const CELL = 80;

    private const GROOVE = 20;

    private const PITCH = self::CELL + self::GROOVE;

    /** The width and height of the board itself: nine squares and eight grooves. */
    private const BOARD = 9 * self::CELL + 8 * self::GROOVE;

    /** The height of each tray row (Black's above the board, White's below). */
    private const TRAY = 70;

    private const PAWN_MOVE = '/^([a-i][1-9])-([a-i][1-9])$/';

    private const FILES = 'abcdefghi';

    /** The row (0 = rank 1) on which each side's pawn wins. */
    private const GOAL = ['w' => 8, 'b' => 0];

    /** Square index steps up, right, down and left; squares count a1 = 0, b1 = 1, … a2 = 9. */
    private const STEPS = [9, 1, -9, -1];

    /** Blocks are numbered 0-63 for horizontal and 64-127 for vertical ones, row * 8 + file of their square. */
    private const BLOCK_CODES = 128;

    public function start(): mixed
    {
        return [
            'pawns' => ['w' => 4, 'b' => 76],
            'left' => ['w' => self::BLOCKS, 'b' => self::BLOCKS],
            'turn' => 'w',
            'blocks' => [],
            'quiet' => 0,
        ];
    }

    public function serialize(mixed $position): string
    {
        $blocks = [];

        foreach ($position['blocks'] as $code => $setter) {
            $blocks[] = self::blockName($code).$setter;
        }

        sort($blocks, SORT_STRING);

        return self::squareName($position['pawns']['w']).' '.self::squareName($position['pawns']['b'])
            .' '.$position['left']['w'].' '.$position['left']['b']
            .' '.$position['turn']
            .' '.($blocks === [] ? '-' : implode(',', $blocks))
            .' '.$position['quiet'];
    }

    public function deserialize(string $position): mixed
    {
        $block = '[a-h][1-8][hv][wb]';

        if (preg_match("/^([a-i][1-9]) ([a-i][1-9]) (\\d{1,2}) (\\d{1,2}) ([wb]) (-|{$block}(?:,{$block})*) (\\d{1,4})$/", $position, $parts) !== 1) {
            throw new InvalidArgumentException("No Blockli position: {$position}");
        }

        $pawns = ['w' => self::square($parts[1]), 'b' => self::square($parts[2])];
        $left = ['w' => (int) $parts[3], 'b' => (int) $parts[4]];
        $set = ['w' => 0, 'b' => 0];
        $blocks = [];

        foreach ($parts[6] === '-' ? [] : explode(',', $parts[6]) as $entry) {
            $code = self::blockCode(substr($entry, 0, 3));

            // Overlapping or crossing blocks, or the same block twice, are no position of this game.
            if ($code === null || ! self::fits($blocks, $code)) {
                throw new InvalidArgumentException("No Blockli position: {$position}");
            }

            $setter = $entry[3] === 'b' ? 'b' : 'w';
            $blocks[$code] = $setter;
            $set[$setter]++;
        }

        if ($pawns['w'] === $pawns['b']
            || $left['w'] + $set['w'] !== self::BLOCKS
            || $left['b'] + $set['b'] !== self::BLOCKS
            || self::distanceFrom($blocks, $pawns['w'], 'w') === null
            || self::distanceFrom($blocks, $pawns['b'], 'b') === null) {
            throw new InvalidArgumentException("No Blockli position: {$position}");
        }

        return ['pawns' => $pawns, 'left' => $left, 'turn' => $parts[5] === 'b' ? 'b' : 'w', 'blocks' => $blocks, 'quiet' => (int) $parts[7]];
    }

    public function turn(mixed $position): string
    {
        return $position['turn'];
    }

    public function legalMoves(mixed $position): array
    {
        // A pawn on its goal rank has ended the game.
        if ($this->winner($position) !== null) {
            return [];
        }

        $side = $position['turn'];
        $other = $side === 'w' ? 'b' : 'w';
        $from = self::squareName($position['pawns'][$side]);
        $moves = array_map(fn (int $to): string => $from.'-'.self::squareName($to), self::pawnTargets($position['blocks'], $position['pawns'][$side], $position['pawns'][$other]));

        if ($position['left'][$side] > 0) {
            foreach (self::blockCodes($position['blocks'], $position['pawns']) as $code) {
                $moves[] = self::blockName($code);
            }
        }

        return $moves;
    }

    public function path(string $move): array
    {
        $code = self::blockCode($move);

        if ($code !== null) {
            return [self::crossingName($code % 64), $code < 64 ? 'h' : 'v'];
        }

        return preg_match(self::PAWN_MOVE, $move, $parts) === 1 ? [$parts[1], $parts[2]] : [];
    }

    public function apply(mixed $position, string $move): mixed
    {
        $side = $position['turn'];
        $code = self::blockCode($move);

        if ($code !== null) {
            $position['blocks'][$code] = $side;
            $position['left'][$side]--;
            $position['quiet'] = 0;
        } elseif (preg_match(self::PAWN_MOVE, $move, $parts) === 1 && self::square($parts[1]) === $position['pawns'][$side]) {
            $position['pawns'][$side] = self::square($parts[2]);
            $position['quiet']++;
        } else {
            throw new InvalidArgumentException("No Blockli move: {$move}");
        }

        $position['turn'] = $side === 'w' ? 'b' : 'w';

        return $position;
    }

    public function notation(mixed $position, string $move): string
    {
        return preg_match(self::PAWN_MOVE, $move, $parts) === 1 ? $parts[2] : $move;
    }

    public function outcome(mixed $position, array $history): ?array
    {
        $winner = $this->winner($position);

        if ($winner !== null) {
            return ['result' => $winner === 'w' ? '1-0' : '0-1', 'reason' => 'goal'];
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
            'goal' => 'Reached the far side',
            // lang/de.json translates this label for 21 times.
            'repetition' => 'The same position '.self::REPETITIONS.' times',
            'no_progress' => 'A hundred moves each without a new block',
        ];
    }

    public function view(mixed $position): array
    {
        $cells = [];
        $points = [];
        $pieces = [];
        $half = intdiv(self::CELL, 2);

        for ($square = 0; $square < 81; $square++) {
            [$x, $y] = self::corner($square);
            $cells[] = ['x' => $x, 'y' => $y, 'size' => self::CELL];
            $points[] = ['id' => self::squareName($square), 'x' => $x + $half, 'y' => $y + $half];
        }

        for ($middle = 0; $middle < 64; $middle++) {
            [$x, $y] = self::corner(intdiv($middle, 8) * 9 + $middle % 8);
            $points[] = ['id' => self::crossingName($middle), 'x' => $x + self::CELL + intdiv(self::GROOVE, 2), 'y' => $y - intdiv(self::GROOVE, 2)];
        }

        // The trays: ten points a side, spread over the width of the board.
        $trays = ['b' => intdiv(self::TRAY, 2), 'w' => self::TRAY + self::BOARD + intdiv(self::TRAY, 2)];

        foreach ($trays as $side => $y) {
            for ($slot = 1; $slot <= self::BLOCKS; $slot++) {
                $points[] = ['id' => "spare-{$side}{$slot}", 'x' => intdiv(self::BOARD * $slot, self::BLOCKS + 1), 'y' => $y];

                if ($slot <= $position['left'][$side]) {
                    $pieces["spare-{$side}{$slot}"] = ['side' => $side, 'kind' => 'spare'];
                }
            }
        }

        foreach (['w', 'b'] as $side) {
            $pieces[self::squareName($position['pawns'][$side])] = ['side' => $side, 'kind' => 'pawn'];
        }

        foreach ($position['blocks'] as $code => $setter) {
            $pieces[self::crossingName($code % 64)] = ['side' => $setter, 'kind' => $code < 64 ? 'block-h' : 'block-v'];
        }

        return [
            'width' => self::BOARD,
            'height' => 2 * self::TRAY + self::BOARD,
            'lines' => [],
            'cells' => $cells,
            'points' => $points,
            'pieces' => $pieces,
            'input' => 'blocks',
            'repetitions' => self::REPETITIONS,
        ];
    }

    /**
     * The race standing of a position (RaceStanding): each side's steps to its
     * goal rank (pawns not counted) and blocks left, scored as steps minus
     * BLOCK_STEPS per block; the lower score leads.
     *
     * @param  Position  $position
     * @return array{w: array{steps: int, blocks: int, score: float}, b: array{steps: int, blocks: int, score: float}, margin: float, lead: Side|null, rate: float}
     */
    public function standing(mixed $position): array
    {
        $white = $this->sideStanding($position, 'w');
        $black = $this->sideStanding($position, 'b');
        $margin = $black['score'] - $white['score'];

        return ['w' => $white, 'b' => $black, 'margin' => $margin, 'lead' => $margin > 0 ? 'w' : ($margin < 0 ? 'b' : null), 'rate' => self::BLOCK_STEPS];
    }

    /**
     * One side's part of the race standing.
     *
     * @param  Position  $position
     * @param  Side  $side
     * @return array{steps: int, blocks: int, score: float}
     */
    private function sideStanding(array $position, string $side): array
    {
        $steps = $this->distance($position, $side) ?? 0;
        $blocks = $position['left'][$side];

        return ['steps' => $steps, 'blocks' => $blocks, 'score' => $steps - self::BLOCK_STEPS * $blocks];
    }

    /**
     * The fewest steps the side's pawn needs to its goal rank, pawns not
     * counted; null if it has no way (never in a position of a game).
     *
     * @param  Position  $position
     * @param  Side  $side
     */
    public function distance(array $position, string $side): ?int
    {
        return self::distanceFrom($position['blocks'], $position['pawns'][$side], $side);
    }

    /**
     * The side whose pawn stands on its goal rank, if any.
     *
     * @param  Position  $position
     * @return Side|null
     */
    private function winner(array $position): ?string
    {
        return match (true) {
            intdiv($position['pawns']['w'], 9) === self::GOAL['w'] => 'w',
            intdiv($position['pawns']['b'], 9) === self::GOAL['b'] => 'b',
            default => null,
        };
    }

    /**
     * Where a pawn on $from may go: the four steps, a jump straight over the
     * other pawn, or beside it when a block or the edge stands behind it.
     *
     * @param  Blocks  $blocks
     * @return list<int>
     */
    private static function pawnTargets(array $blocks, int $from, int $other): array
    {
        $targets = [];

        foreach (self::STEPS as $direction => $step) {
            if (! self::open($blocks, $from, $direction)) {
                continue;
            }

            $next = $from + $step;

            if ($next !== $other) {
                $targets[] = $next;

                continue;
            }

            if (self::open($blocks, $next, $direction)) {
                $targets[] = $next + $step;

                continue;
            }

            foreach ([($direction + 1) % 4, ($direction + 3) % 4] as $aside) {
                if (self::open($blocks, $next, $aside) && ! in_array($next + self::STEPS[$aside], $targets, true)) {
                    $targets[] = $next + self::STEPS[$aside];
                }
            }
        }

        return $targets;
    }

    /**
     * Every block that may be set: it fits in, and both pawns keep a way to
     * their goal rank. Only a block that cuts a pawn's present shortest way
     * can take its last one, so only then is the way searched again.
     *
     * @param  Blocks  $blocks
     * @param  array{w: int, b: int}  $pawns
     * @return list<int>
     */
    private static function blockCodes(array $blocks, array $pawns): array
    {
        $ways = ['w' => self::wayEdges($blocks, $pawns['w'], 'w'), 'b' => self::wayEdges($blocks, $pawns['b'], 'b')];
        $codes = [];

        for ($code = 0; $code < self::BLOCK_CODES; $code++) {
            if (! self::fits($blocks, $code)) {
                continue;
            }

            $cut = self::cutEdges($code);
            $with = $blocks;
            $with[$code] = 'w';
            $keeps = true;

            foreach (['w', 'b'] as $side) {
                if (array_intersect_key($cut, $ways[$side]) !== [] && self::distanceFrom($with, $pawns[$side], $side) === null) {
                    $keeps = false;

                    break;
                }
            }

            if ($keeps) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Whether a block fits in: on the board, not on the middle of another
     * (the same block, or one crossing it) and not overlapping one in line.
     *
     * @param  Blocks  $blocks
     */
    private static function fits(array $blocks, int $code): bool
    {
        if ($code < 0 || $code >= self::BLOCK_CODES) {
            return false;
        }

        $middle = $code % 64;

        if (isset($blocks[$middle]) || isset($blocks[$middle + 64])) {
            return false;
        }

        if ($code < 64) {
            $file = $middle % 8;

            return ! ($file > 0 && isset($blocks[$code - 1])) && ! ($file < 7 && isset($blocks[$code + 1]));
        }

        $row = intdiv($middle, 8);

        return ! ($row > 0 && isset($blocks[$code - 8])) && ! ($row < 7 && isset($blocks[$code + 8]));
    }

    /**
     * Whether a pawn may step from $square in this direction (0 up, 1 right,
     * 2 down, 3 left): no edge and no block in the way.
     *
     * @param  Blocks  $blocks
     */
    private static function open(array $blocks, int $square, int $direction): bool
    {
        $row = intdiv($square, 9);
        $file = $square % 9;

        return match ($direction) {
            0 => $row < 8 && ! ($file < 8 && isset($blocks[$row * 8 + $file])) && ! ($file > 0 && isset($blocks[$row * 8 + $file - 1])),
            2 => $row > 0 && ! ($file < 8 && isset($blocks[($row - 1) * 8 + $file])) && ! ($file > 0 && isset($blocks[($row - 1) * 8 + $file - 1])),
            1 => $file < 8 && ! ($row < 8 && isset($blocks[64 + $row * 8 + $file])) && ! ($row > 0 && isset($blocks[64 + ($row - 1) * 8 + $file])),
            default => $file > 0 && ! ($row < 8 && isset($blocks[64 + $row * 8 + $file - 1])) && ! ($row > 0 && isset($blocks[64 + ($row - 1) * 8 + $file - 1])),
        };
    }

    /**
     * Breadth-first search from $from to the side's goal rank: the distance
     * and the goal square reached (both null without a way), and each
     * reached square's predecessor.
     *
     * @param  Blocks  $blocks
     * @param  Side  $side
     * @return array{0: int|null, 1: int|null, 2: array<int, int>}
     */
    private static function search(array $blocks, int $from, string $side): array
    {
        $goal = self::GOAL[$side];

        if (intdiv($from, 9) === $goal) {
            return [0, $from, []];
        }

        $distance = [$from => 0];
        $before = [];
        $queue = [$from];

        for ($head = 0; $head < count($queue); $head++) {
            $square = $queue[$head];

            foreach (self::STEPS as $direction => $step) {
                $next = $square + $step;

                if (isset($distance[$next]) || ! self::open($blocks, $square, $direction)) {
                    continue;
                }

                $distance[$next] = $distance[$square] + 1;
                $before[$next] = $square;

                if (intdiv($next, 9) === $goal) {
                    return [$distance[$next], $next, $before];
                }

                $queue[] = $next;
            }
        }

        return [null, null, $before];
    }

    /**
     * @param  Blocks  $blocks
     * @param  Side  $side
     */
    private static function distanceFrom(array $blocks, int $from, string $side): ?int
    {
        return self::search($blocks, $from, $side)[0];
    }

    /**
     * The passages along one shortest way of the pawn, keyed like cutEdges().
     *
     * @param  Blocks  $blocks
     * @param  Side  $side
     * @return array<int, true>
     */
    private static function wayEdges(array $blocks, int $from, string $side): array
    {
        [, $square, $before] = self::search($blocks, $from, $side);
        $edges = [];

        // Walk back from the goal square; a pawn without a way or already home has no passage to lose.
        while ($square !== null && $square !== $from) {
            $edges[self::edge($square, $before[$square])] = true;
            $square = $before[$square];
        }

        return $edges;
    }

    /**
     * The two passages between squares a block closes.
     *
     * @return array<int, true>
     */
    private static function cutEdges(int $code): array
    {
        $middle = $code % 64;
        $a = intdiv($middle, 8) * 9 + $middle % 8;

        return $code < 64
            ? [self::edge($a, $a + 9) => true, self::edge($a + 1, $a + 10) => true]
            : [self::edge($a, $a + 1) => true, self::edge($a + 9, $a + 10) => true];
    }

    private static function edge(int $a, int $b): int
    {
        return min($a, $b) * 81 + max($a, $b);
    }

    private static function square(string $name): int
    {
        return ((int) $name[1] - 1) * 9 + (int) strpos(self::FILES, $name[0]);
    }

    private static function squareName(int $square): string
    {
        return self::FILES[$square % 9].(intdiv($square, 9) + 1);
    }

    /** The code of a block written like `e3h`, null for anything else. */
    private static function blockCode(string $name): ?int
    {
        if (preg_match('/^([a-h])([1-8])([hv])$/', $name, $parts) !== 1) {
            return null;
        }

        return ($parts[3] === 'v' ? 64 : 0) + ((int) $parts[2] - 1) * 8 + (int) strpos(self::FILES, $parts[1]);
    }

    private static function blockName(int $code): string
    {
        $middle = $code % 64;

        return self::FILES[$middle % 8].(intdiv($middle, 8) + 1).($code < 64 ? 'h' : 'v');
    }

    /** The crossing of the grooves at the top right corner of a block's square, named after the squares it lies between (`e3/f4`). */
    private static function crossingName(int $middle): string
    {
        $square = intdiv($middle, 8) * 9 + $middle % 8;

        return self::squareName($square).'/'.self::squareName($square + 10);
    }

    /**
     * The top left corner of a square in the view box, rank 9 at the top,
     * below Black's tray.
     *
     * @return array{0: int, 1: int}
     */
    private static function corner(int $square): array
    {
        return [($square % 9) * self::PITCH, self::TRAY + (8 - intdiv($square, 9)) * self::PITCH];
    }

    /** A serialized position without its counter of quiet plies. */
    private static function placement(string $serialized): string
    {
        return substr($serialized, 0, (int) strrpos($serialized, ' '));
    }
}
