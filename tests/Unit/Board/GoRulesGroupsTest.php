<?php

use App\Support\Board\GoRules;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
|--------------------------------------------------------------------------
| Go's groups in one pass answer as the fill per stone did (security N1, 2026-11-14)
|--------------------------------------------------------------------------
|
| GoRules read a position with one flood fill per stone and judged a move with
| one per neighbour of every empty point: a legal game of 559 plies on 19 x 19
| (one side fills every point but those of an odd row and an odd file, the
| other passes) made every answer of the game's page cost 50 ms. Now the groups
| and their liberties are found once per position. The rules stay what they
| were: the functions below are the old ones, kept here as the reference, and
| seeded random games are compared with them at every ply: the legal moves,
| the board after the move (what it captured), the area count, and which
| position texts are read or refused.
|
*/

/**
 * The old GoRules, as it stood before the groups were found in one pass.
 */
final class GoRulesReference
{
    /** @var list<list<int>> */
    private array $neighbours = [];

    public function __construct(private readonly int $size)
    {
        for ($point = 0; $point < $size * $size; $point++) {
            $row = intdiv($point, $size);
            $file = $point % $size;
            $this->neighbours[] = array_values(array_filter([$row > 0 ? $point - $size : null, $file > 0 ? $point - 1 : null, $file < $size - 1 ? $point + 1 : null, $row < $size - 1 ? $point + $size : null], fn (?int $next): bool => $next !== null));
        }
    }

    /**
     * @param  array{board: string, turn: 'w'|'b', ko: int|null, passes: int}  $position
     * @return list<int|'pass'>
     */
    public function legalMoves(array $position): array
    {
        if ($position['passes'] >= 2) {
            return [];
        }

        $board = $position['board'];
        $own = $position['turn'] === 'w' ? 'x' : 'o';
        $moves = [];

        for ($row = $this->size - 1; $row >= 0; $row--) {
            for ($file = 0; $file < $this->size; $file++) {
                $point = $row * $this->size + $file;

                if ($board[$point] === '.' && $point !== $position['ko'] && $this->placeable($board, $point, $own)) {
                    $moves[] = $point;
                }
            }
        }

        $moves[] = 'pass';

        return $moves;
    }

    /** The board after a stone of `own` on `at`: the other colour's groups without a liberty come off. */
    public function place(string $board, int $at, string $own): string
    {
        $board[$at] = $own;

        foreach ($this->neighbours[$at] as $neighbour) {
            if ($board[$neighbour] === '.' || $board[$neighbour] === $own) {
                continue;
            }

            [$stones, $liberties] = $this->group($board, $neighbour);

            if ($liberties === []) {
                foreach ($stones as $stone) {
                    $board[$stone] = '.';
                }
            }
        }

        return $board;
    }

    /** Whether a group stands without a liberty: such a text is no position. */
    public function hasDeadGroup(string $board): bool
    {
        for ($point = 0; $point < $this->size * $this->size; $point++) {
            if ($board[$point] !== '.' && $this->group($board, $point)[1] === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The empty points that touch one colour only, by colour, as indices in rising order.
     *
     * @return array{x: list<int>, o: list<int>}
     */
    public function owners(string $board): array
    {
        $owners = ['x' => [], 'o' => []];
        $seen = [];

        for ($point = 0; $point < $this->size * $this->size; $point++) {
            if ($board[$point] !== '.' || isset($seen[$point])) {
                continue;
            }

            $region = [];
            $touches = [];
            $stack = [$point];
            $seen[$point] = true;

            while ($stack !== []) {
                $at = array_pop($stack);
                $region[] = $at;

                foreach ($this->neighbours[$at] as $neighbour) {
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

        sort($owners['x']);
        sort($owners['o']);

        return $owners;
    }

    private function placeable(string $board, int $at, string $own): bool
    {
        foreach ($this->neighbours[$at] as $neighbour) {
            if ($board[$neighbour] === '.') {
                return true;
            }
        }

        foreach ($this->neighbours[$at] as $neighbour) {
            $liberties = count($this->group($board, $neighbour)[1]);

            if ($board[$neighbour] === $own ? $liberties > 1 : $liberties === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<int>, 1: array<int, true>}
     */
    private function group(string $board, int $at): array
    {
        $colour = $board[$at];
        $stack = [$at];
        $seen = [$at => true];
        $stones = [];
        $liberties = [];

        while ($stack !== []) {
            $point = array_pop($stack);
            $stones[] = $point;

            foreach ($this->neighbours[$point] as $neighbour) {
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
}

/**
 * Seeded random legal games, each ply compared with the reference; the number of plies compared and of captures seen.
 *
 * @return array{plies: int, captures: int, differences: list<string>}
 */
function goCompareGames(int $size, int $games, int $plies, int $seed): array
{
    $rules = new GoRules($size);
    $reference = new GoRulesReference($size);
    $random = new Randomizer(new Mt19937($seed));
    $compared = 0;
    $captures = 0;
    $differences = [];

    for ($game = 0; $game < $games; $game++) {
        $position = $rules->start();

        for ($ply = 0; $ply < $plies; $ply++) {
            $where = "{$size} x {$size}, game {$game}, ply {$ply}: ".$rules->serialize($position);
            $expected = array_map(fn (int|string $move): string => is_int($move) ? $rules->id($move) : $move, $reference->legalMoves($position));
            $legal = $rules->legalMoves($position);

            if ($legal !== $expected) {
                $differences[] = "legal moves at {$where}";
            }

            // The text is read back as the position it was written from.
            if ($rules->deserialize($rules->serialize($position)) !== $position) {
                $differences[] = "text at {$where}";
            }

            $owners = $reference->owners($position['board']);
            $count = $rules->areaCount($position);

            if ($count['owners'] !== ['w' => array_map($rules->id(...), $owners['x']), 'b' => array_map($rules->id(...), $owners['o'])]
                || $count['w']['area'] !== count($owners['x']) || $count['b']['area'] !== count($owners['o'])) {
                $differences[] = "area at {$where}";
            }

            if ($legal === []) {
                break;
            }

            // A stone wherever one may go; a pass now and then, and where nothing else is left.
            $stones = array_slice($legal, 0, -1);
            $move = $stones === [] || $random->getInt(1, 40) === 1 ? GoRules::PASS : $stones[$random->getInt(0, count($stones) - 1)];
            $next = $rules->apply($position, $move);

            if ($move !== GoRules::PASS) {
                $own = $position['turn'] === 'w' ? 'x' : 'o';
                $placed = $reference->place($position['board'], (int) $rules->index($move), $own);
                // One empty point less for the stone; as many or more than before: stones came off.
                $captures += substr_count($placed, '.') >= substr_count($position['board'], '.') ? 1 : 0;

                if ($next['board'] !== $placed) {
                    $differences[] = "captures of {$move} at {$where}";
                }
            }

            $position = $next;
            $compared++;
        }
    }

    return ['plies' => $compared, 'captures' => $captures, 'differences' => $differences];
}

test('200 seeded random games on 9 x 9: the legal moves, the captures and the area count are the old ones at every ply', function () {
    $run = goCompareGames(9, games: 200, plies: 90, seed: 21);

    expect($run['differences'])->toBe([])
        ->and($run['plies'])->toBeGreaterThan(12_000)
        ->and($run['captures'])->toBeGreaterThan(1_000);
});

test('seeded random games on 13 x 13 and 19 x 19 answer as the old rules did', function (int $size, int $games, int $plies) {
    $run = goCompareGames($size, $games, $plies, seed: 21 + $size);

    expect($run['differences'])->toBe([])
        // A game ends early where both passed in a row.
        ->and($run['plies'])->toBeGreaterThan($games * $plies / 2)
        ->and($run['captures'])->toBeGreaterThan(50);
})->with([
    '13 x 13' => [13, 4, 300],
    '19 x 19' => [19, 3, 500],
]);

test('a board with a group that has no liberty is refused, and every other one is read, as before', function () {
    $random = new Randomizer(new Mt19937(21));
    $refused = 0;
    $read = 0;

    foreach ([9, 19] as $size) {
        $rules = new GoRules($size);
        $reference = new GoRulesReference($size);

        for ($board = 0; $board < 150; $board++) {
            // Stones thrown onto the board, few to nearly full: groups with and without a liberty.
            $empty = $random->getInt(1, 9);
            $stones = '';

            for ($point = 0; $point < $size * $size; $point++) {
                $stones .= $random->getInt(1, 10) <= $empty ? '.' : ($random->getInt(0, 1) === 1 ? 'x' : 'o');
            }

            $text = $rules->serialize(['board' => $stones, 'turn' => 'w', 'ko' => null, 'passes' => 0]);
            $dead = $reference->hasDeadGroup($stones);
            $dead ? $refused++ : $read++;

            if ($dead) {
                expect(fn () => $rules->deserialize($text))->toThrow(InvalidArgumentException::class, 'A group without a liberty: '.$text);
            } else {
                expect($rules->deserialize($text)['board'])->toBe($stones);
            }
        }
    }

    expect($refused)->toBeGreaterThan(50)->and($read)->toBeGreaterThan(50);
});
