<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Go (weiqi, baduk) on 9 x 9, 13 x 13 or 19 x 19 (DerCaddy, 2026-10-27: "nach dem Vorbild von Blockli das Spiel Go
 * auf esports bringen", all three sizes), plain PHP without the framework.
 *
 * Rules, as played here:
 * - Black moves first. The core calls the side that moves first `w`, so Black is `w` and White is `b`; every stone
 *   says its colour (`kind` black or white), so a board draws it right, and the game names its sides
 *   (App\Games\Go::sideColours()).
 * - A move puts a stone on an empty intersection, or passes. A group (stones of one colour joined along the lines)
 *   with no empty neighbour left (no liberty) is captured: its stones come off. The other side's groups are captured
 *   first, so a stone that takes the last liberty of a group lives; a stone that would leave its own group without
 *   a liberty and captures nothing is suicide, and not allowed.
 * - Ko: a single stone that captured a single stone, with that stone's point as its only liberty, may not be taken
 *   back at once: the other side plays elsewhere (or passes) first. The same stones with the same side to move a
 *   third time draw the game (a triple ko and the like): as the Japanese rules end such a cycle without a winner.
 * - Two passes in a row end the game, and it is counted as it stands (DerCaddy, 2026-10-27: play it out, then count;
 *   no dead stones are marked): each side has its stones on the board and the empty points that touch only its
 *   stones. A dead stone counts as long as it stands, so it is captured before passing; under area scoring a stone
 *   played into one's own area costs nothing. White gets KOMI for moving second: 7.5 points, and with its half point a
 *   counted game is never drawn.
 * - A game that reaches MOVE_LIMIT plies per point of the board (4 x 81 on 9 x 9) is counted as it stands, so no
 *   game, not even one move a day, runs on for ever.
 *
 * Sources, read 2026-10-27: John Tromp's Tromp-Taylor rules (tromp.github.io/go.html), rule 8 (the game ends after
 * two consecutive passes) and rule 9 (a player's score is the points of her colour plus the empty points that reach
 * only her colour); the Japanese rules of 1989 (www.cs.cmu.edu/~wjh/go/rules/Japanese.html), article 6 (ko), article
 * 12 (a repeated whole-board position ends the game without result, there if both agree, here at the third time as a
 * draw) and articles 3 and 4 (no move that leaves one's own stones without a liberty: Tromp-Taylor alone allow
 * suicide); a komi of 7.5 points as under the Chinese and the AGA rules. Positional superko (Tromp-Taylor rule 6) is
 * not enforced: legalMoves() sees one position, and the draw on the third repetition ends what it would forbid.
 *
 * Points are named as on every go board: files a to t without i (it looks like j), ranks 1 to the size from the
 * bottom. A move is the point (`d4`) or `pass`; its notation is the point in capitals (`D4`) or `Pass`.
 *
 * The position is the board in rows from the top, each row with `x` (black), `o` (white) and the number of empty
 * points between them, then the colour to move, the ko point (`-` for none) and the passes in a row: the start of
 * 9 x 9 is `9/9/9/9/9/9/9/9/9 x - 0`.
 *
 * @phpstan-type Position array{board: string, turn: 'w'|'b', ko: int|null, passes: int}
 *
 * @implements BoardRules<Position>
 * @implements AreaCount<Position>
 */
final class GoRules implements AreaCount, BoardRules
{
    /** The board sizes the league plays. */
    public const SIZES = [9, 13, 19];

    /** White's points for moving second (Chinese and AGA rules): half a point, so a counted game never ties. */
    public const KOMI = 7.5;

    /** The same stones with the same side to move this often draw the game. */
    public const REPETITIONS = 3;

    /** A game is counted after this many plies per point of the board, whatever happens (324 on 9 x 9, 1444 on 19 x 19). */
    public const MOVE_LIMIT = 4;

    public const PASS = 'pass';

    /** The files of the largest board: a to t without i. */
    public const FILES = 'abcdefghjklmnopqrst';

    /** The star points (hoshi), dots on the board as on a real one. */
    public const STARS = [
        9 => ['c3', 'g3', 'e5', 'c7', 'g7'],
        13 => ['d4', 'k4', 'g7', 'd10', 'k10'],
        19 => ['d4', 'k4', 'q4', 'd10', 'k10', 'q10', 'd16', 'k16', 'q16'],
    ];

    /** SVG units between two lines, and around the outer lines (room for a stone on the edge and the coordinates). */
    public const UNIT = 100;

    public const MARGIN = 100;

    /** The colour of a side's stones in the position text. */
    private const STONE = ['w' => 'x', 'b' => 'o'];

    /** @var array<int, list<list<int>>> the neighbours of every point, by board size */
    private static array $neighbours = [];

    public function __construct(private readonly int $size = 9)
    {
        if (! in_array($size, self::SIZES, true)) {
            throw new InvalidArgumentException("No go board of size {$size}.");
        }
    }

    public function size(): int
    {
        return $this->size;
    }

    public function start(): mixed
    {
        return ['board' => str_repeat('.', $this->size * $this->size), 'turn' => 'w', 'ko' => null, 'passes' => 0];
    }

    public function serialize(mixed $position): string
    {
        $rows = [];

        for ($row = 0; $row < $this->size; $row++) {
            $rows[] = (string) preg_replace_callback('/\.+/', fn (array $run): string => (string) strlen($run[0]), substr($position['board'], $row * $this->size, $this->size));
        }

        return implode('/', $rows).' '.self::STONE[$position['turn']].' '.($position['ko'] === null ? '-' : $this->id($position['ko'])).' '.$position['passes'];
    }

    public function deserialize(string $position): mixed
    {
        if (preg_match('#^([0-9xo/]+) ([xo]) (-|[a-hj-t]\d{1,2}) ([0-2])$#', $position, $parts) !== 1) {
            throw new InvalidArgumentException("No go position: {$position}");
        }

        $rows = explode('/', $parts[1]);
        $board = '';

        foreach ($rows as $row) {
            $expanded = (string) preg_replace_callback('/\d+/', fn (array $run): string => str_repeat('.', min((int) $run[0], $this->size + 1)), $row);

            if (strlen($expanded) !== $this->size) {
                throw new InvalidArgumentException("No row of {$this->size} points: {$position}");
            }

            $board .= $expanded;
        }

        if (count($rows) !== $this->size) {
            throw new InvalidArgumentException("No {$this->size} rows: {$position}");
        }

        $ko = $parts[3] === '-' ? null : $this->index($parts[3]);

        if ($parts[3] !== '-' && ($ko === null || $board[$ko] !== '.')) {
            throw new InvalidArgumentException("No empty ko point: {$position}");
        }

        $parsed = ['board' => $board, 'turn' => $parts[2] === 'o' ? 'b' : 'w', 'ko' => $ko, 'passes' => (int) $parts[4]];

        // One text per position: the counts of empty points are written as serialize() writes them.
        if ($this->serialize($parsed) !== $position) {
            throw new InvalidArgumentException("Not the text of a go position: {$position}");
        }

        for ($point = 0; $point < $this->size * $this->size; $point++) {
            if ($board[$point] !== '.' && $this->group($board, $point)[1] === []) {
                throw new InvalidArgumentException("A group without a liberty: {$position}");
            }
        }

        return $parsed;
    }

    public function turn(mixed $position): string
    {
        return $position['turn'];
    }

    public function legalMoves(mixed $position): array
    {
        if ($position['passes'] >= 2) {
            return [];
        }

        $board = $position['board'];
        $own = self::STONE[$position['turn']];
        $moves = [];

        // From a1 on, rank by rank, as the points are read on the board.
        for ($row = $this->size - 1; $row >= 0; $row--) {
            for ($file = 0; $file < $this->size; $file++) {
                $point = $row * $this->size + $file;

                if ($board[$point] === '.' && $point !== $position['ko'] && $this->placeable($board, $point, $own)) {
                    $moves[] = $this->id($point);
                }
            }
        }

        $moves[] = self::PASS;

        return $moves;
    }

    public function path(string $move): array
    {
        return $move === self::PASS ? [] : [$move];
    }

    public function apply(mixed $position, string $move): mixed
    {
        $next = ['board' => $position['board'], 'turn' => self::other($position['turn']), 'ko' => null, 'passes' => 0];

        if ($move === self::PASS) {
            $next['passes'] = $position['passes'] + 1;

            return $next;
        }

        $at = $this->index($move);

        if ($at === null || $position['board'][$at] !== '.') {
            throw new InvalidArgumentException("No go move here: {$move}");
        }

        $own = self::STONE[$position['turn']];
        $board = $position['board'];
        $board[$at] = $own;
        $captured = [];

        foreach (self::neighbours($this->size)[$at] as $neighbour) {
            if ($board[$neighbour] === '.' || $board[$neighbour] === $own) {
                continue;
            }

            [$stones, $liberties] = $this->group($board, $neighbour);

            if ($liberties === []) {
                foreach ($stones as $stone) {
                    $board[$stone] = '.';
                    $captured[] = $stone;
                }
            }
        }

        [$stones, $liberties] = $this->group($board, $at);

        if ($liberties === []) {
            throw new InvalidArgumentException("Suicide: {$move}");
        }

        // A single stone that took a single stone and has only its point as a liberty: retaking at once is ko.
        if (count($captured) === 1 && count($stones) === 1 && count($liberties) === 1) {
            $next['ko'] = $captured[0];
        }

        $next['board'] = $board;

        return $next;
    }

    public function notation(mixed $position, string $move): string
    {
        return $move === self::PASS ? 'Pass' : strtoupper($move);
    }

    public function outcome(mixed $position, array $history): ?array
    {
        if ($position['passes'] >= 2) {
            return $this->counted($position, 'score');
        }

        $key = self::repetitionKey($this->serialize($position));
        $seen = count(array_filter($history, fn (string $earlier): bool => self::repetitionKey($earlier) === $key));

        if ($seen >= self::REPETITIONS) {
            return ['result' => '1/2-1/2', 'reason' => 'repetition'];
        }

        if (count($history) - 1 >= $this->moveLimit()) {
            return $this->counted($position, 'move_limit');
        }

        return null;
    }

    public function reasons(): array
    {
        return [
            'score' => 'Counted after both passed',
            'move_limit' => 'Counted at the move limit',
            'repetition' => 'Threefold repetition',
        ];
    }

    /** The plies after which a game is counted as it stands. */
    public function moveLimit(): int
    {
        return self::MOVE_LIMIT * $this->size * $this->size;
    }

    public function view(mixed $position): array
    {
        $far = self::MARGIN + ($this->size - 1) * self::UNIT;
        $lines = [];
        $points = [];
        $pieces = [];

        for ($line = 0; $line < $this->size; $line++) {
            $at = self::MARGIN + $line * self::UNIT;
            $lines[] = [$at, self::MARGIN, $at, $far];
            $lines[] = [self::MARGIN, $at, $far, $at];
        }

        for ($point = 0; $point < $this->size * $this->size; $point++) {
            $id = $this->id($point);
            $points[] = ['id' => $id, 'x' => self::MARGIN + ($point % $this->size) * self::UNIT, 'y' => self::MARGIN + intdiv($point, $this->size) * self::UNIT];
            $stone = $position['board'][$point];

            if ($stone !== '.') {
                $pieces[$id] = $stone === 'x' ? ['side' => 'w', 'kind' => 'black'] : ['side' => 'b', 'kind' => 'white'];
            }
        }

        return [
            'width' => $far + self::MARGIN,
            'height' => $far + self::MARGIN,
            'lines' => $lines,
            'cells' => [],
            'points' => $points,
            'pieces' => $pieces,
            'input' => 'stones',
            'stars' => self::STARS[$this->size],
            'upright' => true,
        ];
    }

    public function areaCount(mixed $position): array
    {
        $board = $position['board'];
        $points = $this->size * $this->size;
        $owners = ['x' => [], 'o' => []];
        $seen = [];

        for ($point = 0; $point < $points; $point++) {
            if ($board[$point] !== '.' || isset($seen[$point])) {
                continue;
            }

            // One region of empty points, and the colours it touches.
            $region = [];
            $touches = [];
            $stack = [$point];
            $seen[$point] = true;

            while ($stack !== []) {
                $at = array_pop($stack);
                $region[] = $at;

                foreach (self::neighbours($this->size)[$at] as $neighbour) {
                    if ($board[$neighbour] !== '.') {
                        $touches[$board[$neighbour]] = true;
                    } elseif (! isset($seen[$neighbour])) {
                        $seen[$neighbour] = true;
                        $stack[] = $neighbour;
                    }
                }
            }

            if (count($touches) === 1) {
                array_push($owners[array_key_first($touches)], ...$region);
            }
        }

        $sides = [];

        foreach (self::STONE as $side => $stone) {
            sort($owners[$stone]);
            $stones = substr_count($board, $stone);
            $area = count($owners[$stone]);
            $sides[$side] = ['stones' => $stones, 'area' => $area, 'score' => $stones + $area + ($side === 'b' ? self::KOMI : 0.0)];
        }

        $margin = $sides['w']['score'] - $sides['b']['score'];

        return [
            'w' => $sides['w'],
            'b' => $sides['b'],
            'komi' => self::KOMI,
            'margin' => $margin,
            'lead' => $margin > 0 ? 'w' : ($margin < 0 ? 'b' : null),
            'owners' => ['w' => array_map($this->id(...), $owners['x']), 'b' => array_map($this->id(...), $owners['o'])],
        ];
    }

    /**
     * The point's index on the board (rows from the top), or null for a name that is no point of this board.
     */
    public function index(string $id): ?int
    {
        if (preg_match('/^([a-hj-t])(\d{1,2})$/', $id, $parts) !== 1) {
            return null;
        }

        $file = strpos(self::FILES, $parts[1]);
        $rank = (int) $parts[2];

        if ($file === false || $file >= $this->size || $rank < 1 || $rank > $this->size || (string) $rank !== $parts[2]) {
            return null;
        }

        return ($this->size - $rank) * $this->size + $file;
    }

    /** The name of a point by its index (rows from the top): `a1` bottom left. */
    public function id(int $point): string
    {
        return self::FILES[$point % $this->size].($this->size - intdiv($point, $this->size));
    }

    /**
     * Counted as it stands: the side with the higher score wins.
     *
     * @param  Position  $position
     * @return array{result: '1-0'|'0-1'|'1/2-1/2', reason: string}
     */
    private function counted(array $position, string $reason): array
    {
        $lead = $this->areaCount($position)['lead'];

        return ['result' => match ($lead) {
            'w' => '1-0',
            'b' => '0-1',
            default => '1/2-1/2',
        }, 'reason' => $reason];
    }

    /**
     * Whether a stone of this colour may go on this empty point: it has a liberty of its own, it joins a group of its
     * colour that keeps another one, or it takes the last liberty of a group of the other colour (which comes off).
     */
    private function placeable(string $board, int $at, string $own): bool
    {
        $neighbours = self::neighbours($this->size)[$at];

        foreach ($neighbours as $neighbour) {
            if ($board[$neighbour] === '.') {
                return true;
            }
        }

        foreach ($neighbours as $neighbour) {
            $liberties = count($this->group($board, $neighbour)[1]);

            // The point itself is one of the group's liberties.
            if ($board[$neighbour] === $own ? $liberties > 1 : $liberties === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The group of the stone on this point and its liberties.
     *
     * @return array{0: list<int>, 1: array<int, true>}
     */
    private function group(string $board, int $at): array
    {
        $colour = $board[$at];
        $neighbours = self::neighbours($this->size);
        $stack = [$at];
        $seen = [$at => true];
        $stones = [];
        $liberties = [];

        while ($stack !== []) {
            $point = array_pop($stack);
            $stones[] = $point;

            foreach ($neighbours[$point] as $neighbour) {
                if ($board[$neighbour] === '.') {
                    $liberties[$neighbour] = true;
                } elseif ($board[$neighbour] === $colour && ! isset($seen[$neighbour])) {
                    $seen[$neighbour] = true;
                    $stack[] = $neighbour;
                }
            }
        }

        return [$stones, $liberties];
    }

    /**
     * The points next to each point along the lines, rows from the top.
     *
     * @return list<list<int>>
     */
    private static function neighbours(int $size): array
    {
        if (! isset(self::$neighbours[$size])) {
            $all = [];

            for ($point = 0; $point < $size * $size; $point++) {
                $row = intdiv($point, $size);
                $file = $point % $size;
                $next = [];

                if ($row > 0) {
                    $next[] = $point - $size;
                }

                if ($file > 0) {
                    $next[] = $point - 1;
                }

                if ($file < $size - 1) {
                    $next[] = $point + 1;
                }

                if ($row < $size - 1) {
                    $next[] = $point + $size;
                }

                $all[] = $next;
            }

            self::$neighbours[$size] = $all;
        }

        return self::$neighbours[$size];
    }

    /**
     * @param  'w'|'b'  $side
     * @return 'w'|'b'
     */
    private static function other(string $side): string
    {
        return $side === 'w' ? 'b' : 'w';
    }

    /** A serialized position without its ko point and passes: the stones and the colour to move. */
    private static function repetitionKey(string $serialized): string
    {
        $parts = explode(' ', $serialized);

        return $parts[0].' '.($parts[1] ?? '');
    }
}
