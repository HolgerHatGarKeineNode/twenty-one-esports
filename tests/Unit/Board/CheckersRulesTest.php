<?php

use App\Support\Board\CheckersRules;

/*
|--------------------------------------------------------------------------
| Checkers by German rules (plan "Mühle und Dame", P4)
|--------------------------------------------------------------------------
|
| Every rule and every edge case of CheckersRules from a hand-made position,
| with the legal moves worked out by hand. Squares as in chess: a1 is dark
| and in White's bottom left corner; `w`/`b` are men, `W`/`B` kings.
|
*/

/**
 * A position with only these pieces on the board.
 *
 * @param  array<string, 'w'|'b'|'W'|'B'>  $pieces
 * @param  'w'|'b'  $turn
 * @return array{board: array<string, 'w'|'b'|'W'|'B'|null>, turn: 'w'|'b', quiet: int}
 */
function checkersPosition(array $pieces, string $turn = 'w', int $quiet = 0): array
{
    $board = array_fill_keys(CheckersRules::squares(), null);

    foreach ($pieces as $square => $piece) {
        expect($board)->toHaveKey($square);
        $board[$square] = $piece;
    }

    return ['board' => $board, 'turn' => $turn, 'quiet' => $quiet];
}

/**
 * @param  array<string, 'w'|'b'|'W'|'B'>  $pieces
 * @param  'w'|'b'  $turn
 * @return list<string>
 */
function checkersMoves(array $pieces, string $turn = 'w'): array
{
    $moves = (new CheckersRules)->legalMoves(checkersPosition($pieces, $turn));
    sort($moves);

    return $moves;
}

/**
 * The pieces left on the board.
 *
 * @param  array{board: array<string, 'w'|'b'|'W'|'B'|null>, turn: 'w'|'b', quiet: int}  $position
 * @return array<string, string>
 */
function checkersPieces(array $position): array
{
    return array_filter($position['board'], fn (?string $piece): bool => $piece !== null);
}

/* ---------- Board and start ------------------------------------------------------------------------------- */

test('the board is the 32 dark squares, a1 dark, and each side starts with twelve men on its three nearest ranks, White to move', function () {
    $rules = new CheckersRules;
    $start = $rules->start();

    expect(CheckersRules::squares())->toHaveCount(32)
        ->toContain('a1', 'c1', 'b2', 'h2', 'a7', 'h8')
        ->not->toContain('b1')
        ->and(array_keys(checkersPieces($start), 'w'))->toBe(['a1', 'c1', 'e1', 'g1', 'b2', 'd2', 'f2', 'h2', 'a3', 'c3', 'e3', 'g3'])
        ->and(array_keys(checkersPieces($start), 'b'))->toBe(['b6', 'd6', 'f6', 'h6', 'a7', 'c7', 'e7', 'g7', 'b8', 'd8', 'f8', 'h8'])
        ->and($rules->turn($start))->toBe('w')
        ->and($rules->serialize($start))->toBe('wwwwwwwwwwww........bbbbbbbbbbbb w 0');
});

test('White opens with one of the seven forward steps of the men on rank 3', function () {
    $moves = (new CheckersRules)->legalMoves((new CheckersRules)->start());
    sort($moves);

    expect($moves)->toBe(['a3-b4', 'c3-b4', 'c3-d4', 'e3-d4', 'e3-f4', 'g3-f4', 'g3-h4']);
});

test('a position survives serialize and deserialize, and text that is no position is refused', function () {
    $rules = new CheckersRules;
    $position = checkersPosition(['a1' => 'W', 'h8' => 'B', 'd4' => 'w', 'e5' => 'b'], 'b', 17);

    expect($rules->deserialize($rules->serialize($position)))->toBe($position)
        ->and(fn () => $rules->deserialize('wwww w 0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $rules->deserialize('wwwwwwwwwwww........bbbbbbbbbbbb x 0'))->toThrow(InvalidArgumentException::class);
});

/* ---------- Men ---------------------------------------------------------------------------------------- */

test('a man steps one square diagonally forward only, White up the board and Black down', function () {
    expect(checkersMoves(['d4' => 'w', 'h8' => 'b']))->toBe(['d4-c5', 'd4-e5'])
        ->and(checkersMoves(['e5' => 'b', 'a1' => 'w'], 'b'))->toBe(['e5-d4', 'e5-f4'])
        // An own piece in the way blocks the step; the edge of the board leaves one.
        ->and(checkersMoves(['a3' => 'w', 'c3' => 'w', 'b4' => 'w']))->toBe(['b4-a5', 'b4-c5', 'c3-d4']);
});

test('capturing is compulsory: while a man can capture, no step is legal', function () {
    // c3 can jump d4 to e5; a1 could step to b2, but must not.
    expect(checkersMoves(['c3' => 'w', 'a1' => 'w', 'd4' => 'b']))->toBe(['c3xe5']);
});

test('a man captures backward too, both colours', function () {
    // White d4 jumps the black man behind it on c3 and lands on b2.
    expect(checkersMoves(['d4' => 'w', 'c3' => 'b']))->toBe(['d4xb2'])
        // Black e5 jumps the white man behind it (for Black) on f6 and lands on g7.
        ->and(checkersMoves(['e5' => 'b', 'f6' => 'w'], 'b'))->toBe(['e5xg7']);
});

test('a man does not capture its own pieces nor two pieces in a row, and needs the square behind free', function () {
    expect(checkersMoves(['c3' => 'w', 'd4' => 'w']))->toBe(['c3-b4', 'd4-c5', 'd4-e5'])
        ->and(checkersMoves(['c3' => 'w', 'd4' => 'b', 'e5' => 'b']))->toBe(['c3-b4'])
        ->and(checkersMoves(['g3' => 'w', 'h4' => 'b']))->toBe(['g3-f4']);
});

/* ---------- Capture chains ------------------------------------------------------------------------------ */

test('a capture chain must be completed: the move is the whole chain, never a part of it', function () {
    // a1 jumps b2 to c3 and must go on over d4 to e5.
    expect(checkersMoves(['a1' => 'w', 'b2' => 'b', 'd4' => 'b']))->toBe(['a1xc3xe5']);
});

test('the player chooses freely among the chains: a shorter one is as legal as a longer one (no majority capture)', function () {
    // e3 can jump d4 (then b6 on to a7: two pieces) or f4 (one piece): both are legal.
    expect(checkersMoves(['e3' => 'w', 'd4' => 'b', 'f4' => 'b', 'b6' => 'b']))->toBe(['e3xc5xa7', 'e3xg5']);
});

test('the player chooses freely which piece captures: a man or a king, one piece or two', function () {
    // Man a1 can take two (b2, d4), man e1 one (f2), king b8 one (c7): all three are legal.
    expect(checkersMoves(['a1' => 'w', 'b2' => 'b', 'd4' => 'b', 'e1' => 'w', 'f2' => 'b', 'b8' => 'W', 'c7' => 'b']))
        ->toBe(['a1xc3xe5', 'b8xd6', 'e1xg3']);
});

test('no piece is jumped twice: a jumped piece stays on the board until the move ends and blocks the way back', function () {
    // After d4 jumps e5 to f6, the way back over e5 is closed; so for the king.
    expect(checkersMoves(['d4' => 'w', 'e5' => 'b']))->toBe(['d4xf6'])
        ->and(checkersMoves(['d4' => 'W', 'e5' => 'b']))->toBe(['d4xf6']);
});

test('a chain may cross or land on a square it passed before, even its own start square', function () {
    // c3 goes round the square of four black men: over d4, d6, b6 and b4 back to c3, either way round.
    $rules = new CheckersRules;
    $position = checkersPosition(['c3' => 'w', 'd4' => 'b', 'd6' => 'b', 'b6' => 'b', 'b4' => 'b']);

    expect(checkersMoves(['c3' => 'w', 'd4' => 'b', 'd6' => 'b', 'b6' => 'b', 'b4' => 'b']))->toBe(['c3xa5xc7xe5xc3', 'c3xe5xc7xa5xc3'])
        ->and(checkersPieces($rules->apply($position, 'c3xe5xc7xa5xc3')))->toBe(['c3' => 'w']);
});

test('captured pieces leave the board with the move, the capturing piece stands on the last square and Black is to move', function () {
    $rules = new CheckersRules;
    $after = $rules->apply(checkersPosition(['a1' => 'w', 'b2' => 'b', 'd4' => 'b', 'h8' => 'b'], 'w', 7), 'a1xc3xe5');

    expect(checkersPieces($after))->toBe(['e5' => 'w', 'h8' => 'b'])
        ->and($after['turn'])->toBe('b')
        ->and($after['quiet'])->toBe(0);
});

/* ---------- Kings --------------------------------------------------------------------------------------- */

test('a king moves any distance along its four diagonals, forward and backward, up to the first piece', function () {
    // d4: e5 f6 g7 h8, c5 b6 a7, e3 f2 g1, c3 b2 a1.
    expect(checkersMoves(['d4' => 'W']))->toBe(['d4-a1', 'd4-a7', 'd4-b2', 'd4-b6', 'd4-c3', 'd4-c5', 'd4-e3', 'd4-e5', 'd4-f2', 'd4-f6', 'd4-g1', 'd4-g7', 'd4-h8'])
        // A black man on a1 with no square behind it stops the diagonal before it.
        ->and(checkersMoves(['d4' => 'W', 'a1' => 'b']))->toHaveCount(12)->not->toContain('d4-a1')
        // An own man on f6 stops the long diagonal after e5.
        ->and(checkersMoves(['d4' => 'W', 'f6' => 'w']))->not->toContain('d4-f6', 'd4-g7', 'd4-h8')->toContain('d4-e5');
});

test('a king captures a single piece from a distance and lands on the square directly behind it', function () {
    // a1 over b2 and c3 (empty) to the man on d4; it lands on e5, not on f6, g7 or h8.
    expect(checkersMoves(['a1' => 'W', 'd4' => 'b']))->toBe(['a1xe5'])
        // Black kings capture the same way.
        ->and(checkersMoves(['h8' => 'B', 'e5' => 'w'], 'b'))->toBe(['h8xd4']);
});

test('a king cannot capture when the square directly behind the piece is taken, nor two pieces in a row', function () {
    expect(checkersMoves(['a1' => 'W', 'd4' => 'b', 'e5' => 'b']))->toBe(['a1-b2', 'a1-c3'])
        // b2 has White's own man on c3 behind it (which cannot jump back over b2 either: a1 is taken).
        ->and(checkersMoves(['a1' => 'W', 'b2' => 'b', 'c3' => 'w']))->toBe(['c3-b4', 'c3-d4']);
});

test('a king chain turns corners and must be completed as well', function () {
    // a1 over c3 to d4, then over e3 to f2; from f2 nothing is left to take.
    expect(checkersMoves(['a1' => 'W', 'c3' => 'b', 'e3' => 'b']))->toBe(['a1xd4xf2']);
});

/* ---------- Promotion ----------------------------------------------------------------------------------- */

test('a man that reaches the far rank becomes a king, White on rank 8 and Black on rank 1', function () {
    $rules = new CheckersRules;

    expect(checkersPieces($rules->apply(checkersPosition(['g7' => 'w', 'a3' => 'b']), 'g7-h8')))->toBe(['a3' => 'b', 'h8' => 'W'])
        ->and(checkersPieces($rules->apply(checkersPosition(['b2' => 'b', 'h6' => 'w'], 'b'), 'b2-a1')))->toBe(['a1' => 'B', 'h6' => 'w']);
});

test('a man that reaches the far rank by a capture is crowned there and the move ends, even if it could capture on', function () {
    // f6 jumps e7 to d8. As a man it could go on backward over c7 to b6, as a king too: the move ends on d8.
    $rules = new CheckersRules;
    $position = checkersPosition(['f6' => 'w', 'e7' => 'b', 'c7' => 'b']);

    expect($rules->legalMoves($position))->toBe(['f6xd8'])
        ->and(checkersPieces($rules->apply($position, 'f6xd8')))->toBe(['c7' => 'b', 'd8' => 'W']);
});

test('a man that crosses no far rank in a chain stays a man', function () {
    $rules = new CheckersRules;
    // d4 jumps backward over c3 to b2: rank 2 is not White's far rank.
    expect(checkersPieces($rules->apply(checkersPosition(['d4' => 'w', 'c3' => 'b', 'h8' => 'b']), 'd4xb2')))->toBe(['b2' => 'w', 'h8' => 'b']);
});

/* ---------- End of the game ------------------------------------------------------------------------------ */

test('a side with no pieces left loses', function () {
    $rules = new CheckersRules;
    $after = $rules->apply(checkersPosition(['c3' => 'w', 'd4' => 'b']), 'c3xe5');

    expect($rules->outcome($after, [$rules->serialize($after)]))->toBe(['result' => '1-0', 'reason' => 'no_pieces'])
        ->and($rules->outcome(checkersPosition(['c3' => 'b'], 'w'), []))->toBe(['result' => '0-1', 'reason' => 'no_pieces']);
});

test('a side that cannot move loses', function () {
    $rules = new CheckersRules;
    // Black's man b2 has a1 and c1 in front, both White's, and nothing to jump.
    expect($rules->outcome(checkersPosition(['b2' => 'b', 'a1' => 'w', 'c1' => 'w'], 'b'), []))->toBe(['result' => '1-0', 'reason' => 'no_moves'])
        // White's man h2 has Black's g3 in front with f4 taken behind it: no step, no jump.
        ->and($rules->outcome(checkersPosition(['h2' => 'w', 'g3' => 'b', 'f4' => 'b'], 'w'), []))->toBe(['result' => '0-1', 'reason' => 'no_moves']);
});

test('25 moves of each side with only kings moving and nothing captured draw the game', function () {
    $rules = new CheckersRules;
    $after = $rules->apply(checkersPosition(['a1' => 'W', 'h8' => 'B'], 'w', 49), 'a1-b2');

    expect($after['quiet'])->toBe(50)
        ->and($rules->outcome($after, []))->toBe(['result' => '1/2-1/2', 'reason' => 'no_progress'])
        ->and($rules->outcome($rules->apply(checkersPosition(['a1' => 'W', 'h8' => 'B'], 'w', 48), 'a1-b2'), []))->toBeNull();
});

test('a man move or a capture starts the count of quiet moves again', function () {
    $rules = new CheckersRules;

    expect($rules->apply(checkersPosition(['c3' => 'w', 'h8' => 'B'], 'w', 49), 'c3-d4')['quiet'])->toBe(0)
        ->and($rules->apply(checkersPosition(['a1' => 'W', 'c3' => 'b', 'h8' => 'B'], 'w', 49), 'a1xd4')['quiet'])->toBe(0);
});

test('the same position with the same side to move for the third time draws the game', function () {
    $rules = new CheckersRules;
    $here = checkersPosition(['a1' => 'W', 'h8' => 'B'], 'w', 4);
    $other = checkersPosition(['b2' => 'W', 'h8' => 'B'], 'w', 5);
    $otherSide = checkersPosition(['a1' => 'W', 'h8' => 'B'], 'b', 6);
    $s = fn (array $position): string => $rules->serialize($position);

    // The quiet counter differs between the three, the squares and the side to move do not.
    $thrice = [$s(checkersPosition(['a1' => 'W', 'h8' => 'B'], 'w', 0)), $s($other), $s(checkersPosition(['a1' => 'W', 'h8' => 'B'], 'w', 2)), $s($here)];

    expect($rules->outcome($here, $thrice))->toBe(['result' => '1/2-1/2', 'reason' => 'repetition'])
        ->and($rules->outcome($here, array_slice($thrice, 1)))->toBeNull()
        // The same squares with the other side to move are another position.
        ->and($rules->outcome($here, [$s($otherSide), $s($otherSide), $s($here)]))->toBeNull();
});

test('every reason code the rules end a game with has a label', function () {
    expect(array_keys((new CheckersRules)->reasons()))->toBe(['no_pieces', 'no_moves', 'repetition', 'no_progress']);
});

/* ---------- Moves as clicks, notation, drawing --------------------------------------------------------------- */

test('a move is clicked square by square, and the notation is the move', function () {
    $rules = new CheckersRules;

    expect($rules->path('c3-d4'))->toBe(['c3', 'd4'])
        ->and($rules->path('a1xc3xe5'))->toBe(['a1', 'c3', 'e5'])
        ->and($rules->notation($rules->start(), 'c3-d4'))->toBe('c3-d4')
        ->and($rules->notation(checkersPosition(['a1' => 'w', 'b2' => 'b', 'd4' => 'b']), 'a1xc3xe5'))->toBe('a1xc3xe5');
});

test('no legal move\'s path begins another\'s, over two whole games played from the start', function (bool $last, string $end) {
    $rules = new CheckersRules;
    $position = $rules->start();
    $history = [$rules->serialize($position)];
    $moved = [];

    // Always the first or always the last legal move: two fixed, repeatable games with captures, a chain and kings.
    while ($rules->outcome($position, $history) === null && count($moved) < 400) {
        $moves = $rules->legalMoves($position);
        $paths = array_map($rules->path(...), $moves);

        foreach ($paths as $i => $a) {
            foreach ($paths as $j => $b) {
                if ($i !== $j) {
                    expect(array_slice($b, 0, count($a)) === $a)->toBeFalse("{$moves[$i]} begins {$moves[$j]}");
                }
            }
        }

        $moved[] = $move = $last ? $moves[count($moves) - 1] : $moves[0];
        $position = $rules->apply($position, $move);
        $history[] = $rules->serialize($position);
    }

    expect($rules->outcome($position, $history))->toBe(['result' => $end, 'reason' => 'no_pieces'])
        ->and(array_filter($moved, fn (string $move): bool => str_contains($move, 'x')))->not->toBeEmpty();
})->with([
    'always the last move' => [true, '0-1'],
    'always the first move' => [false, '1-0'],
]);

test('the board is drawn as eight by eight squares with the 32 dark ones as cells and clickable points, kings marked', function () {
    $view = (new CheckersRules)->view(checkersPosition(['a1' => 'w', 'h8' => 'B']));

    expect([$view['width'], $view['height']])->toBe([800, 800])
        ->and($view['cells'])->toHaveCount(32)
        ->and($view['cells'][0])->toBe(['x' => 0, 'y' => 700, 'size' => 100])
        ->and($view['points'])->toHaveCount(32)
        ->and($view['points'][0])->toBe(['id' => 'a1', 'x' => 50, 'y' => 750])
        ->and($view['points'][31])->toBe(['id' => 'h8', 'x' => 750, 'y' => 50])
        ->and($view['pieces'])->toBe(['a1' => ['side' => 'w', 'kind' => 'man'], 'h8' => ['side' => 'b', 'kind' => 'king']]);
});
