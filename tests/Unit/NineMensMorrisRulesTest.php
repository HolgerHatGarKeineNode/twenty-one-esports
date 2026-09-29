<?php

use App\Support\Board\NineMensMorrisRules;

/*
|--------------------------------------------------------------------------
| Nine men's morris rules (plan "Mühle und Dame", P3)
|--------------------------------------------------------------------------
|
| Every rule and every morris edge case of the plan from a position worked
| out by hand: placing, moving along lines, flying at three men, a mill
| removes one man, not from a mill while others are free, from a mill when
| all are in mills, a double mill removes only one, a mill with nothing to
| remove, loss at two men and when blocked, draw by threefold repetition and
| after fifty moves each without a mill. The sources are named in
| NineMensMorrisRules.
|
*/

/**
 * A position from the men of each side.
 *
 * @param  list<string>  $white
 * @param  list<string>  $black
 * @param  'w'|'b'  $turn
 * @return array{board: array<string, 'w'|'b'|null>, turn: 'w'|'b', hand: array{w: int, b: int}, quiet: int}
 */
function morrisPosition(array $white, array $black, string $turn = 'w', int $whiteHand = 0, int $blackHand = 0, int $quiet = 0): array
{
    $board = array_fill_keys(NineMensMorrisRules::POINTS, null);

    foreach ($white as $point) {
        $board[$point] = 'w';
    }

    foreach ($black as $point) {
        $board[$point] = 'b';
    }

    return ['board' => $board, 'turn' => $turn, 'hand' => ['w' => $whiteHand, 'b' => $blackHand], 'quiet' => $quiet];
}

/**
 * @param  list<string>  $moves
 * @return list<string>
 */
function morrisSorted(array $moves): array
{
    sort($moves);

    return $moves;
}

/**
 * @param  array{board: array<string, 'w'|'b'|null>}  $position
 * @return list<string>
 */
function morrisMen(array $position, string $side): array
{
    return array_keys(array_filter($position['board'], fn (?string $piece): bool => $piece === $side));
}

test('the board: 24 points in standard notation, 16 lines, 12 points with two neighbours, 8 with three, 4 with four', function () {
    $degrees = array_count_values(array_map(fn (string $point): int => count(NineMensMorrisRules::neighbours($point)), NineMensMorrisRules::POINTS));
    ksort($degrees);

    expect(NineMensMorrisRules::POINTS)->toHaveCount(24)
        ->and(NineMensMorrisRules::LINES)->toHaveCount(16)
        ->and($degrees)->toBe([2 => 12, 3 => 8, 4 => 4])
        ->and(morrisSorted(NineMensMorrisRules::neighbours('a1')))->toBe(['a4', 'd1'])
        ->and(morrisSorted(NineMensMorrisRules::neighbours('d2')))->toBe(['b2', 'd1', 'd3', 'f2'])
        ->and(morrisSorted(NineMensMorrisRules::neighbours('c4')))->toBe(['b4', 'c3', 'c5'])
        // The middle of the board joins nothing: d3 and d5 are not neighbours, nor c4 and e4.
        ->and(NineMensMorrisRules::neighbours('d3'))->not->toContain('d5')
        ->and(NineMensMorrisRules::neighbours('c4'))->not->toContain('e4');
});

test('the game starts on an empty board with nine men in each hand, White to place on any of the 24 points', function () {
    $rules = new NineMensMorrisRules;
    $start = $rules->start();

    expect($rules->serialize($start))->toBe('........................ w 9 9 0')
        ->and($rules->turn($start))->toBe('w')
        ->and($rules->legalMoves($start))->toBe(NineMensMorrisRules::POINTS)
        ->and($rules->outcome($start, [$rules->serialize($start)]))->toBeNull();
});

test('placing puts a man from the hand on an empty point and passes the turn', function () {
    $rules = new NineMensMorrisRules;
    $position = $rules->apply($rules->start(), 'd2');

    expect($rules->serialize($position))->toBe('..........w............. b 8 9 1')
        ->and($rules->legalMoves($position))->toHaveCount(23)->not->toContain('d2');

    $position = $rules->apply($position, 'f4');

    expect($rules->serialize($position))->toBe('..........w........b.... w 8 8 2');
});

test('a placement that closes a mill removes one man of the other side and is legal only with the removal', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['a1', 'a4'], ['b4', 'g1'], 'w', 7, 7);

    expect(morrisSorted(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'a7')))))->toBe(['a7xb4', 'a7xg1']);

    $after = $rules->apply($position, 'a7xb4');

    expect(morrisMen($after, 'w'))->toBe(['a1', 'a4', 'a7'])
        ->and(morrisMen($after, 'b'))->toBe(['g1'])
        ->and($after['hand'])->toBe(['w' => 6, 'b' => 7])
        ->and($after['quiet'])->toBe(0)
        ->and($rules->path('a7xb4'))->toBe(['a7', 'b4'])
        ->and($rules->notation($position, 'a7xb4'))->toBe('a7xb4');
});

test('a mill may not take a man out of a mill while the other side has a man outside one', function () {
    $rules = new NineMensMorrisRules;
    // Black's d1 d2 d3 is a mill, b6 stands alone.
    $position = morrisPosition(['a1', 'a4'], ['d1', 'd2', 'd3', 'b6'], 'w', 7, 5);

    expect(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'a7'))))->toBe(['a7xb6']);
});

test('when every man of the other side stands in a mill, a mill may take one of them', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['a1', 'a4'], ['d1', 'd2', 'd3'], 'w', 7, 6);

    expect(morrisSorted(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'a7')))))->toBe(['a7xd1', 'a7xd2', 'a7xd3']);
});

test('a man that closes two mills at once removes only one man', function () {
    $rules = new NineMensMorrisRules;
    // a4 closes a1 a4 a7 and a4 b4 c4 together.
    $position = morrisPosition(['a1', 'a7', 'b4', 'c4'], ['d7', 'g1', 'b6'], 'w', 5, 6);
    $a4 = array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'a4')));

    expect(morrisSorted($a4))->toBe(['a4xb6', 'a4xd7', 'a4xg1'])
        ->and(array_filter($a4, fn (string $move): bool => substr_count($move, 'x') !== 1))->toBe([]);

    $after = $rules->apply($position, 'a4xd7');

    expect(morrisMen($after, 'b'))->toBe(['b6', 'g1'])
        ->and(NineMensMorrisRules::inMill($after['board'], 'a4'))->toBeTrue();
});

test('a mill with no man of the other side on the board removes nothing', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['a1', 'a4'], [], 'w', 5, 5);

    expect($rules->legalMoves($position))->toContain('a7')
        ->and(array_filter($rules->legalMoves($position), fn (string $move): bool => str_contains($move, 'x')))->toBe([])
        ->and($rules->apply($position, 'a7')['quiet'])->toBe(0);
});

test('after all men are placed a man moves along a line to a neighbouring empty point only', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['d2', 'a1', 'g7', 'e4'], ['d1', 'b2', 'c5', 'f6']);

    expect(morrisSorted($rules->legalMoves($position)))->toBe(['a1-a4', 'd2-d3', 'd2-f2', 'e4-e3', 'e4-e5', 'e4-f4', 'g7-d7', 'g7-g4']);

    $after = $rules->apply($position, 'd2-f2');

    expect(morrisMen($after, 'w'))->toBe(['a1', 'e4', 'f2', 'g7'])
        ->and($after['hand'])->toBe(['w' => 0, 'b' => 0])
        ->and($after['turn'])->toBe('b')
        ->and($rules->path('d2-f2'))->toBe(['d2', 'f2']);
});

test('a move that closes a mill removes a man, and opening and closing the same mill again removes another', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['a1', 'a7', 'b4', 'g4'], ['d1', 'g1', 'd7', 'f6']);

    expect(morrisSorted(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'b4-a4')))))
        ->toBe(['b4-a4xd1', 'b4-a4xd7', 'b4-a4xf6', 'b4-a4xg1']);

    $closed = $rules->apply($position, 'b4-a4xg1');

    expect(morrisMen($closed, 'b'))->toBe(['d1', 'd7', 'f6'])
        ->and($rules->path('b4-a4xg1'))->toBe(['b4', 'a4', 'g1'])
        ->and($closed['quiet'])->toBe(0);

    // Black has three men now and flies; White opens the mill and closes it again.
    $position = $rules->apply($closed, 'f6-f4');
    $position = $rules->apply($position, 'a4-b4');
    $position = $rules->apply($position, 'f4-f6');

    expect($position['quiet'])->toBe(3)
        ->and(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'b4-a4'))))->toBe(['b4-a4xd1', 'b4-a4xd7', 'b4-a4xf6']);
});

test('a side down to three men flies to any empty point; with four it does not, and while placing it places', function () {
    $rules = new NineMensMorrisRules;
    $three = morrisPosition(['a1', 'd7', 'g4'], ['b2', 'b6', 'f2', 'f6']);
    $moves = $rules->legalMoves($three);

    // Three men times 17 empty points; no two of the three share a line, so no flight closes a mill.
    expect($moves)->toHaveCount(51)
        ->toContain('a1-g7', 'd7-c3', 'g4-d5')
        ->and($rules->path('a1-g7'))->toBe(['a1', 'g7']);

    $four = morrisPosition(['a1', 'd7', 'g4', 'e4'], ['b2', 'b6', 'f2', 'f6']);

    expect($rules->legalMoves($four))->not->toContain('a1-g7')
        ->and($rules->legalMoves($four))->toContain('a1-a4');

    $placing = morrisPosition(['a1', 'd7', 'g4'], ['b2', 'b6', 'f2'], 'w', 6, 6);

    expect($rules->legalMoves($placing))->toHaveCount(18)
        ->and(array_filter($rules->legalMoves($placing), fn (string $move): bool => str_contains($move, '-')))->toBe([]);
});

test('a flight that closes a mill removes a man', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['a1', 'd1', 'b6'], ['c3', 'c4', 'e5', 'f6']);

    expect(morrisSorted(array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => str_starts_with($move, 'b6-g1')))))
        ->toBe(['b6-g1xc3', 'b6-g1xc4', 'b6-g1xe5', 'b6-g1xf6']);
});

test('a side left with two men loses, in the moving and in the placing phase; with three it plays on', function () {
    $rules = new NineMensMorrisRules;
    $two = morrisPosition(['a1', 'a4', 'a7', 'g4'], ['b2', 'b6'], 'b');
    $placing = morrisPosition(['a1', 'a4', 'a7'], ['b2'], 'b', 3, 1);
    $three = morrisPosition(['a1', 'a4', 'a7', 'g4'], ['b2', 'b6', 'f6'], 'b');

    expect($rules->outcome($two, [$rules->serialize($two)]))->toBe(['result' => '1-0', 'reason' => 'two_men'])
        ->and($rules->legalMoves($two))->toBe([])
        ->and($rules->outcome($placing, [$rules->serialize($placing)]))->toBe(['result' => '1-0', 'reason' => 'two_men'])
        ->and($rules->outcome($three, [$rules->serialize($three)]))->toBeNull()
        ->and($rules->outcome(morrisPosition(['b2', 'b6'], ['a1', 'a4', 'a7'], 'w'), []))->toBe(['result' => '0-1', 'reason' => 'two_men']);

    // The mill that takes the third-last man ends the game.
    $before = morrisPosition(['a1', 'a7', 'b4', 'g4'], ['d1', 'd7', 'f6']);
    $after = $rules->apply($before, 'b4-a4xf6');

    expect($rules->outcome($after, [$rules->serialize($before), $rules->serialize($after)]))->toBe(['result' => '1-0', 'reason' => 'two_men']);
});

test('a side without a move in the moving phase loses; three men are never blocked, nor is a side with men to place', function () {
    $rules = new NineMensMorrisRules;
    // Black's four corners are walled in by a4, d1, d7 and g4.
    $blocked = morrisPosition(['a4', 'd1', 'd7', 'g4', 'd3'], ['a1', 'a7', 'g1', 'g7'], 'b');

    expect($rules->legalMoves($blocked))->toBe([])
        ->and($rules->outcome($blocked, [$rules->serialize($blocked)]))->toBe(['result' => '1-0', 'reason' => 'blocked']);

    $flies = morrisPosition(['a4', 'd1', 'd7', 'g4', 'd3'], ['a1', 'a7', 'g1'], 'b');
    $places = morrisPosition(['a4', 'd1', 'd7', 'g4', 'd3'], ['a1', 'a7', 'g1', 'g7'], 'b', 0, 1);

    expect($rules->legalMoves($flies))->not->toBe([])
        ->and($rules->outcome($flies, [$rules->serialize($flies)]))->toBeNull()
        ->and($rules->legalMoves($places))->not->toBe([])
        ->and($rules->outcome($places, [$rules->serialize($places)]))->toBeNull();

    $whiteBlocked = morrisPosition(['a1', 'a7', 'g1', 'g7'], ['a4', 'd1', 'd7', 'g4'], 'w');

    expect($rules->outcome($whiteBlocked, []))->toBe(['result' => '0-1', 'reason' => 'blocked']);
});

test('the same position a third time is a draw; the quiet-move count does not make it another position', function () {
    $rules = new NineMensMorrisRules;
    $position = morrisPosition(['d2', 'a1', 'g7', 'e4'], ['d1', 'b2', 'c5', 'f6']);
    $history = [$rules->serialize($position)];
    $outcomes = [];

    foreach ([...['g7-g4', 'c5-c4', 'g4-g7', 'c4-c5'], ...['g7-g4', 'c5-c4', 'g4-g7', 'c4-c5']] as $move) {
        $position = $rules->apply($position, $move);
        $history[] = $rules->serialize($position);
        $outcomes[] = $rules->outcome($position, $history);
    }

    // Back at the start after move 4 (twice seen) and move 8 (three times), each time with another quiet count.
    expect($history[0])->toEndWith(' 0')
        ->and($history[4])->toEndWith(' 4')
        ->and($history[8])->toEndWith(' 8')
        ->and(array_slice($outcomes, 0, 7))->toBe(array_fill(0, 7, null))
        ->and($outcomes[7])->toBe(['result' => '1/2-1/2', 'reason' => 'repetition']);

    // The same board with other men in hand is another position.
    $inHand = morrisPosition(['d2', 'a1', 'g7', 'e4'], ['d1', 'b2', 'c5', 'f6'], 'w', 1, 1);

    expect($rules->outcome($inHand, [$rules->serialize($inHand), $history[0], $history[4]]))->toBeNull();
});

test('fifty moves each without a mill draw the game, and a mill starts the count again', function () {
    $rules = new NineMensMorrisRules;
    $quiet = fn (int $plies): array => morrisPosition(['d2', 'a1', 'g7', 'e4'], ['d1', 'b2', 'c5', 'f6'], 'w', 0, 0, $plies);

    $at100 = $rules->apply($quiet(99), 'g7-g4');
    $at99 = $rules->apply($quiet(98), 'g7-g4');

    expect(NineMensMorrisRules::QUIET_PLY_LIMIT)->toBe(100)
        ->and($at100['quiet'])->toBe(100)
        ->and($rules->outcome($at100, [$rules->serialize($at100)]))->toBe(['result' => '1/2-1/2', 'reason' => 'no_mill'])
        ->and($rules->outcome($at99, [$rules->serialize($at99)]))->toBeNull();

    $mill = morrisPosition(['a1', 'a7', 'b4', 'g4'], ['d1', 'g1', 'd7', 'f6'], 'w', 0, 0, 99);

    expect($rules->apply($mill, 'b4-a4xg1')['quiet'])->toBe(0);
});

test('positions round-trip as text, and text that is no position is refused', function (string $text) {
    expect(fn () => (new NineMensMorrisRules)->deserialize($text))->toThrow(InvalidArgumentException::class);
})->with([
    'garbage' => ['nope'],
    'short board' => ['....................... w 9 9 0'],
    'no side' => ['........................ x 9 9 0'],
    'ten white men' => ['wwwwwwwwww.............. b 0 9 0'],
    'nine placed and one in hand' => ['wwwwwwwww............... b 1 9 0'],
]);

test('every reason outcome() gives has a label', function () {
    expect(array_keys((new NineMensMorrisRules)->reasons()))->toBe(['two_men', 'blocked', 'repetition', 'no_mill']);
});

test('the board drawing: a square of 24 points on 16 lines, the men as pieces', function () {
    $rules = new NineMensMorrisRules;
    $view = $rules->view(morrisPosition(['a1'], ['g7']));

    expect($view['width'])->toBe($view['height'])
        ->and($view['points'])->toHaveCount(24)
        ->and($view['lines'])->toHaveCount(16)
        ->and($view['cells'])->toBe([])
        ->and($view['points'][0])->toBe(['id' => 'a1', 'x' => 50, 'y' => 650])
        ->and($view['pieces'])->toBe(['a1' => ['side' => 'w', 'kind' => 'man'], 'g7' => ['side' => 'b', 'kind' => 'man']])
        // Every point inside the view box.
        ->and(array_filter($view['points'], fn (array $point): bool => $point['x'] <= 0 || $point['x'] >= 700 || $point['y'] <= 0 || $point['y'] >= 700))->toBe([]);
});

test('over whole random games: no move path begins another, every position round-trips, and a mill removes at most one man', function (int $seed) {
    $rules = new NineMensMorrisRules;
    mt_srand($seed);
    $position = $rules->start();
    $history = [$rules->serialize($position)];
    $plies = 0;
    $violations = [];

    while ($rules->outcome($position, $history) === null && $plies < 3000) {
        $moves = $rules->legalMoves($position);
        // Sorted, a path that begins another is directly followed by one it begins.
        $paths = array_map(fn (string $move): string => implode(' ', $rules->path($move)).' ', $moves);
        sort($paths);

        foreach (array_slice($paths, 1) as $i => $next) {
            if (str_starts_with($next, $paths[$i])) {
                $violations[] = "{$paths[$i]}begins {$next}";
            }
        }

        $move = $moves[mt_rand(0, count($moves) - 1)];
        $other = $position['turn'] === 'w' ? 'b' : 'w';
        $before = count(morrisMen($position, $other));
        $position = $rules->apply($position, $move);
        $serialized = $rules->serialize($position);

        if ($rules->serialize($rules->deserialize($serialized)) !== $serialized) {
            $violations[] = "{$serialized} does not round-trip";
        }

        if ($before - count(morrisMen($position, $other)) !== (str_contains($move, 'x') ? 1 : 0)) {
            $violations[] = "{$move} removed another number of men";
        }

        $history[] = $serialized;
        $plies++;
    }

    expect($violations)->toBe([])
        ->and($plies)->toBeGreaterThan(18)
        ->and($rules->outcome($position, $history))->not->toBeNull();
})->with([21, 2140, 7]);
