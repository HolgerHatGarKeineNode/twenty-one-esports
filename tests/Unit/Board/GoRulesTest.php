<?php

use App\Support\Board\GoRules;

/*
|--------------------------------------------------------------------------
| Go rules (DerCaddy, 2026-10-27: Go on 9 x 9, 13 x 13 and 19 x 19)
|--------------------------------------------------------------------------
|
| Every rule of GoRules from a position drawn by hand: the names of the
| points, captures (single stones, groups, at the edge), suicide forbidden
| unless the stone captures, ko and what lifts it, a snapback that is no
| ko, two passes ending the game, the count by area as it stands (dead
| stones count until captured, seki points are neutral) with White's komi,
| the third repetition drawing, the move limit counting, and the text of a
| position read strictly. The sources are named in GoRules.
|
*/

/**
 * A position from rows drawn from the top: `x` black, `o` white, `.` empty (spaces are ignored).
 *
 * @param  list<string>  $rows
 * @param  'w'|'b'  $turn  `w` is Black, who moves first
 * @return array{board: string, turn: 'w'|'b', ko: int|null, passes: int}
 */
function goPosition(array $rows, string $turn = 'w', ?string $ko = null, int $passes = 0): array
{
    return (new GoRules(count($rows)))->deserialize(goText($rows, $turn, $ko, $passes));
}

/**
 * The text of a position drawn as goPosition() draws it.
 *
 * @param  list<string>  $rows
 */
function goText(array $rows, string $turn = 'w', ?string $ko = null, int $passes = 0): string
{
    $packed = array_map(fn (string $row): string => (string) preg_replace_callback('/\.+/', fn (array $run): string => (string) strlen($run[0]), str_replace(' ', '', $row)), $rows);

    return implode('/', $packed).' '.($turn === 'w' ? 'x' : 'o').' '.($ko ?? '-').' '.$passes;
}

/**
 * The position as rows drawn from the top, for comparing.
 *
 * @param  array{board: string}  $position
 * @return list<string>
 */
function goRows(array $position, int $size = 9): array
{
    return array_map(fn (string $row): string => implode(' ', str_split($row)), str_split($position['board'], $size));
}

const GO_EMPTY = ['. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .', '. . . . . . . . .'];

test('the three sizes start empty with Black to move; no other size exists', function () {
    foreach ([9, 13, 19] as $size) {
        $rules = new GoRules($size);
        $start = $rules->start();

        expect($rules->serialize($start))->toBe(implode('/', array_fill(0, $size, (string) $size)).' x - 0')
            ->and($rules->turn($start))->toBe('w')
            ->and($rules->legalMoves($start))->toHaveCount($size * $size + 1)
            ->and($rules->legalMoves($start))->toContain('pass');
    }

    expect(fn () => new GoRules(11))->toThrow(InvalidArgumentException::class);
});

test('points are named as on a go board: files a to t without i, rank 1 at the bottom', function () {
    $rules = new GoRules(19);

    expect($rules->id(18 * 19))->toBe('a1')
        ->and($rules->id(0))->toBe('a19')
        ->and($rules->id(19 * 19 - 1))->toBe('t1')
        ->and($rules->id(18))->toBe('t19')
        ->and($rules->id(18 * 19 + 8))->toBe('j1')
        ->and($rules->index('i5'))->toBeNull()
        ->and($rules->index('u1'))->toBeNull()
        ->and($rules->index('a20'))->toBeNull()
        ->and($rules->index('a0'))->toBeNull()
        ->and($rules->index('a05'))->toBeNull()
        ->and((new GoRules(9))->index('k1'))->toBeNull()
        ->and((new GoRules(9))->index('j9'))->toBe(8);

    foreach (range(0, 19 * 19 - 1) as $point) {
        expect($rules->index($rules->id($point)))->toBe($point);
    }
});

test('a move puts a stone, passes the turn, and its notation is the point in capitals; a pass is Pass', function () {
    $rules = new GoRules(9);
    $after = $rules->apply($rules->start(), 'e5');

    expect(goRows($after)[4])->toBe('. . . . x . . . .')
        ->and($rules->turn($after))->toBe('b')
        ->and($rules->legalMoves($after))->not->toContain('e5')
        ->and($rules->notation($rules->start(), 'e5'))->toBe('E5')
        ->and($rules->notation($after, 'pass'))->toBe('Pass')
        ->and($rules->path('e5'))->toBe(['e5'])
        ->and($rules->path('pass'))->toBe([]);
});

test('a stone without liberties comes off, in the middle, at the edge and in the corner', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . x . . . .',
        '. . . x o x . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        'o x . x o x . . .',
    ]);
    $middle = $rules->apply($position, 'e4');

    expect(goRows($middle)[4])->toBe('. . . x . x . . .')
        // A stone that took one stone but keeps more liberties than that point is no ko.
        ->and($middle['ko'])->toBeNull()
        // The white stone on e1 has only e2 left, the one in the corner only a2.
        ->and(goRows($rules->apply($position, 'e2'))[8])->toBe('o x . x . x . . .')
        ->and(goRows($rules->apply($position, 'a2'))[8])->toBe('. x . x o x . . .')
        ->and(goRows($rules->apply($position, 'a2'))[7])->toBe('x . . . . . . . .');
});

test('a whole group comes off when its last liberty is taken', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . x x x . . .',
        '. . x o o o x . .',
        '. . x o . o x . .',
        '. . . x o x . . .',
        '. . . . x . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ], 'w');

    $after = $rules->apply($position, 'e5');

    expect(goRows($after))->toBe([
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . x x x . . .',
        '. . x . . . x . .',
        '. . x . x . x . .',
        '. . . x . x . . .',
        '. . . . x . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ])->and($after['ko'])->toBeNull();
});

test('suicide is not allowed, unless the stone captures: then it lives', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. x . . . . . . .',
        'x . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        'x o . . . . . . .',
        '. x o . . . . . .',
    ], 'b');

    // White on a9 would have no liberty and capture nothing: suicide.
    expect($rules->legalMoves($position))->not->toContain('a9')
        ->and(fn () => $rules->apply($position, 'a9'))->toThrow(InvalidArgumentException::class)
        // a1 has no liberty either, but it takes the last liberty of the black stone on b1: legal, and the stone lives.
        ->and($rules->legalMoves($position))->toContain('a1');

    $after = $rules->apply($position, 'a1');

    expect(goRows($after)[7])->toBe('x o . . . . . . .')
        ->and(goRows($after)[8])->toBe('o . o . . . . . .');
});

test('ko: the stone just captured may not be taken back at once; a move elsewhere or a pass lifts it', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . x o . . . .',
        '. . x o . o . . .',
        '. . . x o . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ], 'w');

    // Black takes the white stone on d5 by playing e5: a ko.
    $ko = $rules->apply($position, 'e5');

    expect(goRows($ko)[4])->toBe('. . x . x o . . .')
        ->and($ko['ko'])->toBe($rules->index('d5'))
        ->and($rules->serialize($ko))->toEndWith(' o d5 0')
        ->and($rules->legalMoves($ko))->not->toContain('d5')
        ->and($rules->legalMoves($ko))->toContain('h8');

    // White plays elsewhere, Black answers elsewhere: now White may take back.
    $later = $rules->apply($rules->apply($ko, 'h8'), 'h2');
    expect($rules->legalMoves($later))->toContain('d5');

    // A pass lifts the ko too.
    $passed = $rules->apply($rules->apply($ko, 'pass'), 'pass');
    expect($passed['ko'])->toBeNull();
});

test('taking two stones is no ko', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. . . x o . . . .',
        '. . . x o x . . .',
        '. . . . x . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ], 'w');

    // The two white stones on e9 and e8 have only f9 left.
    $after = $rules->apply($position, 'f9');

    expect(goRows($after)[0])->toBe('. . . x . x . . .')
        ->and(goRows($after)[1])->toBe('. . . x . x . . .')
        ->and($after['ko'])->toBeNull()
        ->and($rules->legalMoves($after))->toContain('e9', 'e8');
});

test('two passes in a row end the game and count it; a stone between them starts the count again', function () {
    $rules = new GoRules(9);
    $once = $rules->apply($rules->start(), 'pass');

    expect($once['passes'])->toBe(1)
        ->and($rules->outcome($once, []))->toBeNull()
        ->and($rules->apply($once, 'e5')['passes'])->toBe(0);

    $twice = $rules->apply($once, 'pass');

    // An empty board: no stone, no area, White wins by the komi.
    expect($rules->legalMoves($twice))->toBe([])
        ->and($rules->outcome($twice, []))->toBe(['result' => '0-1', 'reason' => 'score'])
        ->and($rules->areaCount($twice)['margin'])->toBe(-7.5);
});

test('the count: stones plus empty points that touch only one colour, komi 7.5 for White', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. . . x o . . . .',
        '. . . x o . . . .',
        '. . . x o . . . .',
        '. . . x o . . . .',
        '. . x x o . . . .',
        '. . x o o . . . .',
        '. . x o . . . . .',
        '. . x o . . . . .',
        '. . x o . . . . .',
    ], 'w', null, 2);

    $count = $rules->areaCount($position);

    // Black: 10 stones and the 22 points left of its wall; White: 10 stones, the four files right of its wall and e1 to e3.
    expect($count['w'])->toBe(['stones' => 10, 'area' => 22, 'score' => 32.0])
        ->and($count['b'])->toBe(['stones' => 10, 'area' => 39, 'score' => 56.5])
        ->and($count['komi'])->toBe(7.5)
        ->and($count['margin'])->toBe(-24.5)
        ->and($count['lead'])->toBe('b')
        ->and($count['owners']['w'])->toContain('a1', 'c9', 'b5')
        ->and($count['owners']['b'])->toContain('j1', 'e3', 'j9')
        ->and($rules->outcome($position, []))->toBe(['result' => '0-1', 'reason' => 'score']);
});

test('nothing is marked dead: a stone left in the other side\'s area counts, and the area around it is nobody\'s', function () {
    $rules = new GoRules(9);
    $rows = [
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
        '. . . . x o . . .',
    ];
    $clean = $rules->areaCount(goPosition($rows, 'w', null, 2));
    $rows[4] = '. o . . x o . . .';
    $dead = $rules->areaCount(goPosition($rows, 'w', null, 2));

    // Clean: Black 9 + 36, White 9 + 27 + 7.5. With a white stone left on b5 Black's left side is nobody's.
    expect($clean['w']['score'])->toBe(45.0)
        ->and($clean['b']['score'])->toBe(43.5)
        ->and($dead['w']['score'])->toBe(9.0)
        ->and($dead['b']['score'])->toBe(44.5)
        ->and($dead['owners']['w'])->toBe([]);
});

test('empty points between both colours are nobody\'s, as the shared liberties of a seki', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        '. o . x . . . . .',
        'o o . x . . . . .',
        'x x x x . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ], 'w', null, 2);

    $count = $rules->areaCount($position);

    // c9 and c8 touch both colours: neutral. a9 touches only white: White's.
    expect($count['owners']['w'])->not->toContain('c9')
        ->and($count['owners']['b'])->not->toContain('c9')
        ->and($count['owners']['b'])->toContain('a9');
});

test('the same stones with the same side to move a third time draw the game', function () {
    $rules = new GoRules(9);
    $position = goPosition(GO_EMPTY);
    $text = $rules->serialize($position);
    $otherKo = goText(GO_EMPTY, 'w', 'e5', 1);

    // The ko point and the passes are not part of the repetition: the stones and the side to move are.
    expect($rules->outcome($position, [$text, $text]))->toBeNull()
        ->and($rules->outcome($position, [$text, $otherKo, $text]))->toBe(['result' => '1/2-1/2', 'reason' => 'repetition'])
        ->and($rules->outcome($position, [$text, goText(GO_EMPTY, 'b'), $text]))->toBeNull();
});

test('a game is counted at the move limit, four plies per point of the board', function () {
    $rules = new GoRules(9);
    $position = goPosition([
        'x . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
        '. . . . . . . . .',
    ]);
    $history = array_map(fn (int $ply): string => "history {$ply}", range(0, 323));

    expect($rules->moveLimit())->toBe(324)
        ->and((new GoRules(19))->moveLimit())->toBe(1444)
        ->and($rules->outcome($position, $history))->toBeNull()
        ->and($rules->outcome($position, [...$history, 'history 324']))->toBe(['result' => '1-0', 'reason' => 'move_limit']);
});

test('the text of a position is read strictly', function () {
    $rules = new GoRules(9);

    expect($rules->serialize($rules->deserialize('9/9/9/9/4x4/9/9/9/9 o e4 0')))->toBe('9/9/9/9/4x4/9/9/9/9 o e4 0');

    foreach ([
        '9/9/9/9/9/9/9/9 x - 0',          // eight rows
        '9/9/9/9/9/9/9/9/10 x - 0',       // a row too long
        '9/9/9/9/9/9/9/9/8 x - 0',        // a row too short
        '9/9/9/9/9/9/9/9/09 x - 0',       // not as serialize() writes it
        '9/9/9/9/9/9/9/9/45 x - 0',       // two counts in a row
        '9/9/9/9/9/9/9/9/9 b - 0',        // no colour
        '9/9/9/9/9/9/9/9/9 x i5 0',       // no point
        '9/9/9/9/9/9/9/9/x8 x a1 0',      // a ko on a stone
        '9/9/9/9/9/9/9/9/9 x - 3',        // three passes
        'xo7/o8/9/9/9/9/9/9/9 x - 0',     // a stone without a liberty
        '9/9/9/9/9/9/9/9/999999999999 x - 0',
    ] as $text) {
        expect(fn () => $rules->deserialize($text))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => (new GoRules(13))->deserialize('9/9/9/9/9/9/9/9/9 x - 0'))->toThrow(InvalidArgumentException::class);
});

test('the board: lines and points of a go board, stones in their colours, star points, the same for both players', function () {
    foreach ([9 => 1000, 13 => 1400, 19 => 2000] as $size => $width) {
        $rules = new GoRules($size);
        $view = $rules->view($rules->apply($rules->apply($rules->start(), 'd4'), 'c3'));

        expect($view['width'])->toBe($width)
            ->and($view['height'])->toBe($width)
            ->and($view['lines'])->toHaveCount(2 * $size)
            ->and($view['cells'])->toBe([])
            ->and($view['points'])->toHaveCount($size * $size)
            ->and($view['points'][0])->toBe(['id' => $rules->id(0), 'x' => 100, 'y' => 100])
            ->and($view['pieces'])->toBe(['d4' => ['side' => 'w', 'kind' => 'black'], 'c3' => ['side' => 'b', 'kind' => 'white']])
            ->and($view['input'])->toBe('stones')
            ->and($view['upright'])->toBeTrue()
            ->and($view['stars'])->toBe(GoRules::STARS[$size]);
    }

    expect(GoRules::STARS[19])->toContain('d4', 'k10', 'q16')->toHaveCount(9);
});

test('random games stay legal: every stone has a liberty, the text reads back, and no game outlives its limit', function () {
    $rules = new GoRules(9);
    mt_srand(27);
    $plies = 0;

    for ($game = 0; $game < 20; $game++) {
        $position = $rules->start();
        $history = [$rules->serialize($position)];

        while ($rules->outcome($position, $history) === null) {
            $legal = $rules->legalMoves($position);
            // Pass rarely, so the board fills and stones get captured.
            $stones = array_values(array_diff($legal, ['pass']));
            $move = $stones !== [] && mt_rand(0, 99) < 97 ? $stones[mt_rand(0, count($stones) - 1)] : 'pass';
            $position = $rules->apply($position, $move);
            $text = $rules->serialize($position);
            $history[] = $text;
            $plies++;

            expect($rules->serialize($rules->deserialize($text)))->toBe($text);
        }

        expect(count($history) - 1)->toBeLessThanOrEqual($rules->moveLimit());
    }

    expect($plies)->toBeGreaterThan(1000);
});
