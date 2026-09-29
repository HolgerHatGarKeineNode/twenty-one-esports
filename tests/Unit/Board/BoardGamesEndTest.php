<?php

use App\Support\Board\BoardRules;
use App\Support\Board\CheckersRules;
use App\Support\Board\NineMensMorrisRules;

/*
|--------------------------------------------------------------------------
| Every board game ends (plan "Mühle und Dame", P8)
|--------------------------------------------------------------------------
|
| A correspondence game has no clock that ends it, so the rules must: both
| rule sets draw after a run of quiet plies (QUIET_PLY_LIMIT), and a quiet
| run may only be broken by progress that cannot go on forever. Each test
| names that progress and plays seeded random games, checking at every ply
| that the quiet count restarts exactly when progress was made. Progress is
| finite (checkers: a number that never grows, the potential; nine men's
| morris: the men taken after the placing), so no game is longer than the
| bound each test derives, and every random game ends within it.
|
*/

/**
 * Plays random legal moves from the start until the rules end the game,
 * checking the potential at every ply.
 *
 * @param  BoardRules<mixed>  $rules
 * @param  Closure(mixed): int  $potential  progress left in a position
 * @param  Closure(mixed): int  $quiet  the position's quiet ply count
 * @return int the plies the game took
 */
function playRandomBoardGame(BoardRules $rules, Closure $potential, Closure $quiet, int $bound): int
{
    $position = $rules->start();
    $history = [$rules->serialize($position)];

    for ($ply = 1; $ply <= $bound; $ply++) {
        $moves = $rules->legalMoves($position);
        expect($moves)->not->toBeEmpty();

        $next = $rules->apply($position, $moves[mt_rand(0, count($moves) - 1)]);
        $progress = $potential($next) < $potential($position);

        expect($potential($next))->toBeLessThanOrEqual($potential($position))
            // Progress resets the quiet count, and nothing else does.
            ->and($quiet($next))->toBe($progress ? 0 : $quiet($position) + 1);

        $position = $next;
        $history[] = $rules->serialize($position);

        if ($rules->outcome($position, $history) !== null) {
            return $ply;
        }
    }

    throw new RuntimeException("A game ran past its bound of {$bound} plies.");
}

test('every game of nine men\'s morris ends within its bound', function () {
    $rules = new NineMensMorrisRules;
    // After the placing, a quiet run ends only with a mill, and every mill then takes a man: at most
    // 2 x (MEN - 2) of them before a side is down to two men. The placing itself is 2 x MEN plies.
    $bound = NineMensMorrisRules::MEN * 2 + (2 * (NineMensMorrisRules::MEN - 2) + 1) * NineMensMorrisRules::QUIET_PLY_LIMIT;
    $men = fn (array $position): int => count(array_filter($position['board']));
    mt_srand(8);

    foreach (range(1, 40) as $game) {
        $position = $rules->start();
        $history = [$rules->serialize($position)];

        for ($ply = 1; ; $ply++) {
            expect($ply)->toBeLessThanOrEqual($bound);
            $moves = $rules->legalMoves($position);
            $next = $rules->apply($position, $moves[mt_rand(0, count($moves) - 1)]);

            // Once both sides have placed every man, the quiet count restarts only when a man is taken.
            if ($position['hand']['w'] + $position['hand']['b'] === 0) {
                expect($next['quiet'])->toBe($men($next) < $men($position) ? 0 : $position['quiet'] + 1);
            }

            $position = $next;
            $history[] = $rules->serialize($position);

            if ($rules->outcome($position, $history) !== null) {
                break;
            }
        }
    }
});

test('every game of checkers ends: only a man\'s step or a capture breaks a quiet run', function () {
    $rules = new CheckersRules;
    // Eight per piece on the board plus the steps each man has left to the far rank: a man's step lowers it by
    // one, each captured piece by eight or more (a man capturing backwards goes back two ranks per jump), a
    // king's move not at all.
    $potential = function (array $position): int {
        $left = 0;

        foreach ($position['board'] as $square => $piece) {
            $rank = (int) substr($square, 1);
            $left += match ($piece) {
                'w' => 8 + 8 - $rank,
                'b' => 8 + $rank - 1,
                'W', 'B' => 8,
                default => 0,
            };
        }

        return $left;
    };
    $bound = ($potential($rules->start()) + 1) * CheckersRules::QUIET_PLY_LIMIT;
    mt_srand(8);

    foreach (range(1, 40) as $game) {
        expect(playRandomBoardGame($rules, $potential, fn (array $position): int => $position['quiet'], $bound))->toBeLessThanOrEqual($bound);
    }
});
