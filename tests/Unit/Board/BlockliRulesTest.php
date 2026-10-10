<?php

use App\Games\Blockli;
use App\Games\GameKind;
use App\Support\Board\BlockliRules;

/*
|--------------------------------------------------------------------------
| Blockli, a race with blocks on 9 x 9
|--------------------------------------------------------------------------
|
| Every rule and edge case of BlockliRules from a hand-made position, with
| the legal moves worked out by hand; then 42 seeded games of the reference
| implementation (the JS engine of the Blockli prototype, 4 117 plies, see
| tests/Fixtures/blockli-vectors.json), replayed ply by ply: the same legal
| moves before every ply, the same final position, the same outcome.
|
| Squares as in chess, files a-i and ranks 1-9; `e1-e2` moves a pawn (its
| notation is `e2`), `e3h` is the horizontal block above e3 and f3, `e3v`
| the vertical block right of e3 and e4.
|
*/

/**
 * A position with these pawns and blocks; each side has left what it has not set.
 *
 * @param  array<string, 'w'|'b'>  $blocks  block => the side that set it
 * @param  'w'|'b'  $turn
 * @return array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}
 */
function blockliPosition(string $white, string $black, array $blocks = [], string $turn = 'w', int $quiet = 0): array
{
    $set = array_count_values($blocks);
    $entries = array_map(fn (string $block, string $setter): string => $block.$setter, array_keys($blocks), array_values($blocks));
    sort($entries);

    return (new BlockliRules)->deserialize(sprintf(
        '%s %s %d %d %s %s %d',
        $white,
        $black,
        BlockliRules::BLOCKS - ($set['w'] ?? 0),
        BlockliRules::BLOCKS - ($set['b'] ?? 0),
        $turn,
        $entries === [] ? '-' : implode(',', $entries),
        $quiet,
    ));
}

/**
 * The squares the pawn of the side to move may go to, sorted.
 *
 * @param  array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}  $position
 * @return list<string>
 */
function blockliSteps(array $position): array
{
    $rules = new BlockliRules;
    $pawn = array_filter($rules->legalMoves($position), fn (string $move): bool => str_contains($move, '-'));
    $targets = array_values(array_map(fn (string $move): string => $rules->notation($position, $move), $pawn));
    sort($targets);

    return $targets;
}

/**
 * The position after the legal move written like this (`e2`, `e3h`).
 *
 * @param  array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}  $position
 * @return array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}
 */
function blockliPlay(array $position, string $notation): array
{
    $rules = new BlockliRules;
    $moves = array_values(array_filter($rules->legalMoves($position), fn (string $move): bool => $rules->notation($position, $move) === $notation));

    expect($moves)->toHaveCount(1, "{$notation} is legal here");

    return $rules->apply($position, $moves[0]);
}

/**
 * The blocks the side to move may set.
 *
 * @param  array{pawns: array{w: int, b: int}, left: array{w: int, b: int}, turn: 'w'|'b', blocks: array<int, 'w'|'b'>, quiet: int}  $position
 * @return list<string>
 */
function blockliBlocks(array $position): array
{
    return array_values(array_filter((new BlockliRules)->legalMoves($position), fn (string $move): bool => strlen($move) === 3));
}

/**
 * @return array<string, array{array{name: string, moves: list<string>, legal: list<string>, result: string, reason: string, final: string}}>
 */
function blockliVectors(): array
{
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/blockli-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    $cases = [];
    foreach ($vectors['games'] as $game) {
        $cases['game '.$game['name']] = [$game];
    }

    return $cases;
}

/* ---------- The game --------------------------------------------------------------------------------------- */

test('Blockli is a board game played one move a day only, one player a side, draws possible, on these rules', function () {
    $game = new Blockli;
    $mode = $game->mode('correspondence');

    expect($game->slug())->toBe('blockli')
        ->and($game->kind())->toBe(GameKind::Board)
        ->and($game->rules())->toBeInstanceOf(BlockliRules::class)
        ->and(array_keys($game->modes()))->toBe(['correspondence'])
        ->and($mode?->timeControl)->toBe('1/86400')
        ->and($mode?->teamSize)->toBe(1)
        ->and($mode?->allowsDraws)->toBeTrue()
        ->and($game->validateResult($mode, ['result' => '1/2-1/2']))->toBe([])
        ->and($game->validateResult($mode, ['result' => '2-0']))->toBe(['result']);

    // Its cover, in both widths and both formats, like the other games.
    $cover = $game->assets()->cover;

    expect($cover?->name)->toBe('blockli');

    foreach ($cover?->widths ?? [] as $width) {
        foreach (['webp', 'jpg'] as $format) {
            expect(__DIR__.'/../../../public/'.$cover?->path($width, $format))->toBeFile();
        }
    }
});

/* ---------- Board and start ------------------------------------------------------------------------------- */

test('the board is 9 x 9, White starts on e1 and Black on e9 with ten blocks each, White to move', function () {
    $rules = new BlockliRules;
    $start = $rules->start();

    expect($rules->serialize($start))->toBe('e1 e9 10 10 w - 0')
        ->and($rules->turn($start))->toBe('w')
        ->and($rules->distance($start, 'w'))->toBe(8)
        ->and($rules->distance($start, 'b'))->toBe(8)
        ->and($rules->outcome($start, [$rules->serialize($start)]))->toBeNull();
});

test('White opens with three pawn steps or any of the 128 blocks', function () {
    $start = (new BlockliRules)->start();

    expect(blockliSteps($start))->toBe(['d1', 'e2', 'f1'])
        ->and(blockliBlocks($start))->toHaveCount(128)
        ->and((new BlockliRules)->legalMoves($start))->toHaveCount(131);
});

/* ---------- Pawn moves ------------------------------------------------------------------------------------ */

test('a pawn steps one square up, down, left or right, never off the board', function () {
    expect(blockliSteps(blockliPosition('e5', 'a9')))->toBe(['d5', 'e4', 'e6', 'f5'])
        ->and(blockliSteps(blockliPosition('a1', 'e9')))->toBe(['a2', 'b1'])
        ->and(blockliSteps(blockliPosition('i5', 'e9')))->toBe(['h5', 'i4', 'i6']);
});

test('a block stops a step', function () {
    // e5h lies above e5 and f5, d4v between d4|e4 and d5|e5.
    expect(blockliSteps(blockliPosition('e5', 'a9', ['e5h' => 'b', 'd4v' => 'b'])))->toBe(['e4', 'f5']);
});

test('facing the other pawn, a pawn jumps straight over it', function () {
    expect(blockliSteps(blockliPosition('e5', 'e6')))->toBe(['d5', 'e4', 'e7', 'f5'])
        ->and(blockliSteps(blockliPosition('e5', 'd5')))->toBe(['c5', 'e4', 'e6', 'f5'])
        ->and(blockliSteps(blockliPosition('e6', 'e5', [], 'b')))->toBe(['d5', 'e4', 'e7', 'f5']);
});

test('with a block behind the other pawn, the pawn steps beside it instead', function () {
    // e6h closes e6|e7: no jump, but d6 and f6 beside Black.
    expect(blockliSteps(blockliPosition('e5', 'e6', ['e6h' => 'b'])))->toBe(['d5', 'd6', 'e4', 'f5', 'f6']);
});

test('with the edge behind the other pawn, the pawn steps beside it as well, and wins there', function () {
    $position = blockliPosition('e8', 'e9');

    expect(blockliSteps($position))->toBe(['d8', 'd9', 'e7', 'f8', 'f9']);

    $rules = new BlockliRules;
    $won = blockliPlay($position, 'f9');

    expect($rules->outcome($won, [$rules->serialize($position), $rules->serialize($won)]))->toBe(['result' => '1-0', 'reason' => 'goal']);
});

test('no step beside the other pawn through a block', function () {
    // e6h closes the jump, d6v the square left of Black (it touches e6h only at its end).
    expect(blockliSteps(blockliPosition('e5', 'e6', ['e6h' => 'b', 'd6v' => 'b'])))->toBe(['d5', 'e4', 'f5', 'f6']);
});

test('a jump is no step through a block: a block between the pawns stops both', function () {
    // e5h closes e5|e6: Black on e6 is no neighbour then.
    expect(blockliSteps(blockliPosition('e5', 'e6', ['e5h' => 'b'])))->toBe(['d5', 'e4', 'f5']);
});

/* ---------- Blocks ---------------------------------------------------------------------------------------- */

test('a block may not overlap a block in line or cross one at its middle, but may touch its end or middle', function () {
    $horizontal = blockliBlocks(blockliPosition('e1', 'e9', ['e3h' => 'b']));

    expect($horizontal)->not->toContain('d3h', 'e3h', 'f3h', 'e3v')
        ->toContain('c3h', 'g3h', 'd3v', 'f3v', 'e2v', 'e4v')
        ->toHaveCount(128 - 4);

    $vertical = blockliBlocks(blockliPosition('e1', 'e9', ['e3v' => 'b']));

    expect($vertical)->not->toContain('e2v', 'e3v', 'e4v', 'e3h')
        ->toContain('e1v', 'e5v', 'd3h', 'f3h', 'e2h', 'e4h');
});

test('no block may take a pawn its last way to the goal, neither the own nor the other', function () {
    // a1h c1h e1h g1h close rank 1 upwards on files a-h: White gets out only through i1|i2.
    $white = blockliPosition('e1', 'e9', ['a1h' => 'b', 'c1h' => 'b', 'e1h' => 'b', 'g1h' => 'b']);

    expect(blockliBlocks($white))->not->toContain('h1v')->toContain('h2v');

    // The same around Black, with White to move: White may not shut Black in either.
    $black = blockliPosition('e1', 'e9', ['a8h' => 'w', 'c8h' => 'w', 'e8h' => 'w', 'g8h' => 'w']);

    expect(blockliBlocks($black))->not->toContain('h8v')->toContain('h7v')
        ->and((new BlockliRules)->distance($black, 'b'))->toBe(12);
});

test('a side without blocks left can only move its pawn', function () {
    $position = (new BlockliRules)->deserialize('e5 e9 0 10 w a1hw,a3hw,a5hw,a7hw,c1hw,c3hw,c5hw,c7hw,g1hw,g3hw 0');

    expect((new BlockliRules)->legalMoves($position))->toBe(['e5-e6', 'e5-f5', 'e5-e4', 'e5-d5']);
});

test('a block counts down the setter\'s blocks and resets the quiet plies', function () {
    $rules = new BlockliRules;
    $position = $rules->apply(blockliPosition('e2', 'e8', [], 'w', 57), 'e5h');

    expect($rules->serialize($position))->toBe('e2 e8 9 10 b e5hw 0');
});

/* ---------- Game end -------------------------------------------------------------------------------------- */

test('the first pawn on its goal rank wins and ends the game', function () {
    $rules = new BlockliRules;

    $white = blockliPlay(blockliPosition('c8', 'g2'), 'c9');
    $black = blockliPlay(blockliPosition('c8', 'g2', [], 'b'), 'g1');

    expect($rules->outcome($white, []))->toBe(['result' => '1-0', 'reason' => 'goal'])
        ->and($rules->legalMoves($white))->toBe([])
        ->and($rules->outcome($black, []))->toBe(['result' => '0-1', 'reason' => 'goal'])
        ->and($rules->legalMoves($black))->toBe([]);
});

test('the same position with the same side to move for the 21st time is a draw, the 20th is not', function () {
    $rules = new BlockliRules;
    $position = $rules->start();
    $history = [$rules->serialize($position)];
    $outcomes = [];

    // To and fro twenty times: every position of the round comes back each round, the start a 21st time after 80 plies.
    foreach (range(1, 20) as $round) {
        foreach (['e2', 'e8', 'e1', 'e9'] as $move) {
            $position = blockliPlay($position, $move);
            $history[] = $rules->serialize($position);
            $outcomes[] = $rules->outcome($position, $history);
        }
    }

    expect(BlockliRules::REPETITIONS)->toBe(21)
        ->and(array_slice($outcomes, 0, 79))->each->toBeNull()
        ->and($outcomes[79])->toBe(['result' => '1/2-1/2', 'reason' => 'repetition'])
        // The quiet plies are no part of the position: they rose all the while.
        ->and($position['quiet'])->toBe(80);
});

test('200 plies without a new block are a draw', function () {
    $rules = new BlockliRules;
    $position = blockliPosition('e2', 'e8', ['e5h' => 'w'], 'w', 199);

    expect($rules->outcome($position, []))->toBeNull()
        ->and($rules->outcome(blockliPlay($position, 'd2'), []))->toBe(['result' => '1/2-1/2', 'reason' => 'no_progress'])
        ->and($rules->outcome($rules->apply($position, 'a8h'), []))->toBeNull();
});

test('every end reason has a label', function () {
    expect(array_keys((new BlockliRules)->reasons()))->toBe(['goal', 'repetition', 'no_progress'])
        ->and((new BlockliRules)->reasons()['repetition'])->toBe('The same position 21 times');
});

test('the race standing counts each side\'s steps to its goal and blocks left, a block worth one and a half steps', function () {
    $rules = new BlockliRules;

    // The start: eight steps and ten blocks each, level.
    expect($rules->standing($rules->start()))->toBe([
        'w' => ['steps' => 8, 'blocks' => 10, 'score' => -7.0],
        'b' => ['steps' => 8, 'blocks' => 10, 'score' => -7.0],
        'margin' => 0.0, 'lead' => null, 'rate' => 1.5,
    ]);

    // White's own block above e6 and f6 sends its pawn round by d6: 4 steps and 9 blocks (-9.5) against 2 and 10 (-13).
    expect($rules->standing(blockliPosition('e6', 'c3', ['e6h' => 'w'])))->toBe([
        'w' => ['steps' => 4, 'blocks' => 9, 'score' => -9.5],
        'b' => ['steps' => 2, 'blocks' => 10, 'score' => -13.0],
        'margin' => -3.5, 'lead' => 'b', 'rate' => 1.5,
    ]);

    // Two steps behind with two blocks more is ahead: 4 and 10 (-11) against 2 and 8 (-10); whose turn it is does not count.
    $behind = blockliPosition('e5', 'e3', ['a1h' => 'b', 'h7v' => 'b']);

    expect($rules->standing($behind))->toBe([
        'w' => ['steps' => 4, 'blocks' => 10, 'score' => -11.0],
        'b' => ['steps' => 2, 'blocks' => 8, 'score' => -10.0],
        'margin' => 1.0, 'lead' => 'w', 'rate' => 1.5,
    ])->and($rules->standing(blockliPosition('e5', 'e3', ['a1h' => 'b', 'h7v' => 'b'], 'b')))->toBe($rules->standing($behind));
});

/* ---------- Positions as text ----------------------------------------------------------------------------- */

test('a position survives serialize and deserialize, blocks sorted and with their setter', function () {
    $rules = new BlockliRules;
    $position = $rules->start();

    foreach (['e2', 'e5h', 'b3v', 'e8'] as $move) {
        $position = blockliPlay($position, $move);
    }

    $text = $rules->serialize($position);

    expect($text)->toBe('e2 e8 9 9 w b3vw,e5hb 1')
        ->and($rules->serialize($rules->deserialize($text)))->toBe($text);
});

test('deserialize refuses text that is no Blockli position', function (string $text) {
    (new BlockliRules)->deserialize($text);
})->throws(InvalidArgumentException::class)->with([
    'garbage' => ['e1 e9'],
    'no such square' => ['j1 e9 10 10 w - 0'],
    'no such side' => ['e1 e9 10 10 x - 0'],
    'block off the board' => ['e1 e9 9 10 w i3hw 0'],
    'both pawns on one square' => ['e5 e5 10 10 w - 0'],
    'blocks left do not add up' => ['e1 e9 10 10 w e3hw 0'],
    'overlapping blocks' => ['e1 e9 8 10 w d3hw,e3hw 0'],
    'crossing blocks' => ['e1 e9 9 9 w e3hw,e3vb 0'],
    'the same block twice' => ['e1 e9 8 10 w e3hw,e3hw 0'],
    'a pawn without a way' => ['e1 e9 5 10 w a1hw,c1hw,e1hw,g1hw,h1vw 0'],
]);

/* ---------- Moves, clicks and the board drawing ----------------------------------------------------------- */

test('a pawn move names its start and target, and its notation only the target', function () {
    $rules = new BlockliRules;
    $start = $rules->start();

    expect($rules->notation($start, 'e1-e2'))->toBe('e2')
        ->and($rules->notation($start, 'e3h'))->toBe('e3h')
        ->and($rules->serialize($rules->apply($start, 'e1-e2')))->toBe('e2 e9 10 10 b - 1');
});

test('apply refuses what is no move of this game, or a pawn move from a square the pawn is not on', function (string $move) {
    $rules = new BlockliRules;
    $rules->apply($rules->start(), $move);
})->throws(InvalidArgumentException::class)->with([
    'garbage' => ['e1e2'],
    'target only' => ['e2'],
    'block off the board' => ['i3h'],
    'another square' => ['d1-d2'],
    'the other pawn' => ['e9-e8'],
]);

test('the path of a pawn move is its start and target, of a block its crossing and direction', function () {
    $rules = new BlockliRules;

    expect($rules->path('e1-e2'))->toBe(['e1', 'e2'])
        ->and($rules->path('e3h'))->toBe(['e3/f4', 'h'])
        ->and($rules->path('e3v'))->toBe(['e3/f4', 'v'])
        ->and($rules->path('a1h'))->toBe(['a1/b2', 'h'])
        ->and($rules->path('h8v'))->toBe(['h8/i9', 'v'])
        ->and($rules->path('i9h'))->toBe([])
        ->and($rules->path('e2'))->toBe([]);
});

test('the view shows the 81 squares, the 64 crossings and a tray of ten a side, and asks for the block input and the repetition warning', function () {
    $rules = new BlockliRules;
    $position = blockliPosition('e2', 'e8', ['e3h' => 'w', 'c6v' => 'b', 'g7h' => 'b'], 'w');
    $view = $rules->view($position);
    $ids = array_column($view['points'], 'id');
    $at = array_column($view['points'], null, 'id');

    expect($view['width'])->toBe(880)
        ->and($view['height'])->toBe(1020)
        ->and($view['input'])->toBe('blocks')
        ->and($view['repetitions'])->toBe(21)
        ->and($view['lines'])->toBe([])
        ->and($view['cells'])->toHaveCount(81)
        ->and($view['cells'][0])->toBe(['x' => 0, 'y' => 870, 'size' => 80])
        ->and($view['points'])->toHaveCount(81 + 64 + 20)
        ->and(array_unique($ids))->toHaveCount(165)
        // a1 bottom left, i9 top right, the crossing between them in the grooves, trays above and below.
        ->and($at['a1'])->toBe(['id' => 'a1', 'x' => 40, 'y' => 910])
        ->and($at['i9'])->toBe(['id' => 'i9', 'x' => 840, 'y' => 110])
        ->and($at['e3/f4'])->toBe(['id' => 'e3/f4', 'x' => 490, 'y' => 660])
        ->and($at['spare-b1'])->toBe(['id' => 'spare-b1', 'x' => 80, 'y' => 35])
        ->and($at['spare-w10'])->toBe(['id' => 'spare-w10', 'x' => 800, 'y' => 985]);

    $pieces = $view['pieces'];

    expect($pieces['e2'])->toBe(['side' => 'w', 'kind' => 'pawn'])
        ->and($pieces['e8'])->toBe(['side' => 'b', 'kind' => 'pawn'])
        ->and($pieces['e3/f4'])->toBe(['side' => 'w', 'kind' => 'block-h'])
        ->and($pieces['c6/d7'])->toBe(['side' => 'b', 'kind' => 'block-v'])
        ->and($pieces['g7/h8'])->toBe(['side' => 'b', 'kind' => 'block-h'])
        // White has 9 blocks left, Black 8: the trays fill from the left.
        ->and(array_keys(array_filter($pieces, fn (array $piece): bool => $piece['kind'] === 'spare' && $piece['side'] === 'w')))
        ->toBe(['spare-w1', 'spare-w2', 'spare-w3', 'spare-w4', 'spare-w5', 'spare-w6', 'spare-w7', 'spare-w8', 'spare-w9'])
        ->and(count(array_filter($pieces, fn (array $piece): bool => $piece['kind'] === 'spare' && $piece['side'] === 'b')))->toBe(8)
        ->and($pieces)->toHaveCount(2 + 3 + 9 + 8);

    // A pawn move ends on a square point, a block starts on a crossing point and ends with its direction;
    // every path has two parts and none repeats, so none begins another.
    $paths = array_map($rules->path(...), $rules->legalMoves($position));
    $squares = array_column(array_slice($view['points'], 0, 81), 'id');

    foreach ($paths as $path) {
        expect($path)->toHaveCount(2)
            ->and(in_array($path[1], ['h', 'v'], true) ? in_array($path[0], $ids, true) : array_diff($path, $squares) === [])->toBeTrue();
    }

    expect(array_unique(array_map(fn (array $path): string => implode(' ', $path), $paths)))->toHaveCount(count($paths));
});

/* ---------- Reference games ------------------------------------------------------------------------------- */

test('Blockli plays every reference game exactly like the reference implementation', function (array $game) {
    $rules = new BlockliRules;
    $position = $rules->start();
    $history = [$rules->serialize($position)];

    foreach ($game['moves'] as $ply => $move) {
        $legal = $rules->legalMoves($position);
        $names = array_map(fn (string $option): string => $rules->notation($position, $option), $legal);
        $played = array_search($move, $names, true);
        sort($names, SORT_STRING);

        expect(substr(md5(implode(',', $names)), 0, 16))->toBe($game['legal'][$ply], 'legal moves before ply '.($ply + 1))
            ->and($played)->not->toBeFalse()
            // A pawn is never stuck, so there is no pass (see BlockliRules).
            ->and(array_filter($legal, fn (string $option): bool => str_contains($option, '-')))->not->toBeEmpty()
            ->and($rules->outcome($position, $history))->toBeNull();

        $position = $rules->apply($position, $legal[$played]);
        $history[] = $rules->serialize($position);
    }

    expect(end($history))->toBe($game['final'])
        ->and($rules->serialize($rules->deserialize($game['final'])))->toBe($game['final'])
        ->and($rules->outcome($position, $history))->toBe(['result' => $game['result'], 'reason' => $game['reason']]);
})->with(blockliVectors());
