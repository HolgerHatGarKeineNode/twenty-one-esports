<?php

use App\Support\Board\BlockspaceRules;

/** A position with these stones (cell names) and the rest of a fresh game. */
function blockspaceWith(array $stones = [], array $change = []): array
{
    $rules = new BlockspaceRules;
    $position = $rules->deserialize($rules->serialize($rules->start()));

    foreach ($stones as $name => $side) {
        $position['board'][(21 - (int) substr($name, 1)) * 9 + strpos('abcdefghi', $name[0])] = $side;
    }

    return [...$position, ...$change];
}

test('every game of the browser engine plays the same on the server, move by move', function () {
    $rules = new BlockspaceRules;
    $games = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/blockspace-games.json'), true);

    expect($games)->toHaveCount(24);
    $steps = 0;

    foreach ($games as $game) {
        $position = $rules->deserialize($game['start']);
        expect($rules->serialize($position))->toBe($game['start']);

        foreach ($game['steps'] as $step) {
            $legal = $rules->legalMoves($position);
            sort($legal);

            expect(count($legal))->toBe($step['legal'])
                ->and(strlen(implode('|', $legal)))->toBe($step['sum'])
                ->and($legal)->toContain($step['move']);

            $position = $rules->apply($position, $step['move']);
            expect(md5($rules->serialize($position)))->toBe($step['after']);
            $steps++;
        }

        $outcome = $rules->outcome($position, []);
        expect($outcome)->not->toBeNull()
            ->and($outcome['result'])->toBe([0 => '1-0', 1 => '0-1', -1 => '1/2-1/2'][$game['winner']]);
    }

    expect($steps)->toBeGreaterThan(500);
});

test('a deal holds each of the 21 forms twice, in an order of its own', function () {
    $rules = new BlockspaceRules;
    $deal = $rules->deal('rapid');
    $letters = count_chars($deal['pool'].$deal['queue'], 1);

    expect($letters)->toHaveCount(21)
        ->and(array_unique($letters))->toBe([array_key_first($letters) => 2])
        ->and(strlen($deal['pool']))->toBe(5)
        ->and($rules->deal('rapid')['deck'])->not->toBe($deal['deck']);
});

test('the blitz mode burns the fuse, the others do not', function () {
    $rules = new BlockspaceRules;

    expect($rules->deal('blitz')['fuse'])->toBeTrue()
        ->and($rules->deal('rapid')['fuse'])->toBeFalse()
        ->and($rules->deal('correspondence')['fuse'])->toBeFalse();
});

test('the start of a game can be read back from any of its positions', function () {
    $rules = new BlockspaceRules;
    $start = $rules->deal('blitz');
    $position = $start;

    foreach (range(1, 6) as $ply) {
        $position = $rules->apply($position, $rules->legalMoves($position)[0]);
    }

    expect($rules->serialize($rules->startOf($position)))->toBe($rules->serialize($start));
});

test('a stone needs its own corner and may not touch its own edge', function () {
    $rules = new BlockspaceRules;
    $position = blockspaceWith(['e2' => 'w'], ['pool' => 'aaaaa', 'played' => ['w' => true, 'b' => false]]);
    $legal = $rules->legalMoves($position);

    expect($legal)->toContain('0:d3')->toContain('0:f3')->toContain('0:d1')->toContain('0:f1')
        ->not->toContain('0:e3')->not->toContain('0:d2')->not->toContain('0:e2')->not->toContain('0:c4');
});

test('the first stones cover the start cell, and the other side\'s stones only take their cells', function () {
    $rules = new BlockspaceRules;

    expect($rules->legalMoves(blockspaceWith([], ['pool' => 'aaaaa'])))->toContain('0:e2')->not->toContain('0:e3');

    $position = blockspaceWith(['e2' => 'w', 'd3' => 'b'], ['pool' => 'aaaaa', 'played' => ['w' => true, 'b' => true]]);
    expect($rules->legalMoves($position))->toContain('0:f3')->not->toContain('0:d3');
    // Next to a stone of the other side is no edge of one's own.
    expect($rules->legalMoves(blockspaceWith(['e2' => 'w', 'f4' => 'b'], ['pool' => 'aaaaa', 'played' => ['w' => true, 'b' => true]])))->toContain('0:f3');
});

test('a rung adds to the fee, and what is taken moves the rest up', function () {
    $rules = new BlockspaceRules;
    $position = blockspaceWith([], ['pool' => 'kkkkk', 'queue' => 'a']);
    $move = collect($rules->legalMoves($position))->first(fn (string $move): bool => str_starts_with($move, '2:'));
    $next = $rules->apply($position, $move);

    expect($next['score']['w'])->toBe(5 + 2)
        ->and($next['pool'])->toBe('kkkka')
        ->and($next['queue'])->toBe('')
        ->and($rules->notation($position, $move))->toStartWith('₿ I5 ')->toEndWith(' +7');
});

test('the turn goes White, Black, Black, White', function () {
    $rules = new BlockspaceRules;
    $position = $rules->deal('rapid');
    $turns = [];

    foreach (range(1, 8) as $ply) {
        $turns[] = $rules->turn($position);
        $position = $rules->apply($position, $rules->legalMoves($position)[0]);
    }

    expect($turns)->toBe(['w', 'b', 'b', 'w', 'w', 'b', 'b', 'w']);
});

test('a pass lets the oldest transaction fall out and hands the turn on', function () {
    $rules = new BlockspaceRules;
    $position = $rules->deal('rapid');
    $next = $rules->apply($position, BlockspaceRules::PASS);

    expect($next['pool'])->toBe(substr($position['pool'], 1).$position['queue'][0])
        ->and($next['passes'])->toBe(1)
        ->and($rules->turn($next))->toBe('b');
});

test('the game ends with the ladder empty, and the higher fees win', function () {
    $rules = new BlockspaceRules;

    expect($rules->outcome(blockspaceWith([], ['pool' => '', 'queue' => '', 'score' => ['w' => 40, 'b' => 39]]), []))->toBe(['result' => '1-0', 'reason' => 'mempool_empty'])
        ->and($rules->outcome(blockspaceWith([], ['pool' => '', 'queue' => '', 'score' => ['w' => 39, 'b' => 40]]), []))->toBe(['result' => '0-1', 'reason' => 'mempool_empty'])
        ->and($rules->outcome(blockspaceWith([], ['pool' => 'a', 'passes' => 14, 'score' => ['w' => 40, 'b' => 40]]), []))->toBe(['result' => '1/2-1/2', 'reason' => 'passes'])
        ->and($rules->legalMoves(blockspaceWith([], ['pool' => '', 'queue' => ''])))->toBe([]);
});

test('the view hides what comes next and says the rest', function () {
    $rules = new BlockspaceRules;
    $position = $rules->deal('blitz');
    $view = $rules->view($position);

    expect($view['input'])->toBe('pieces')
        ->and($view['points'])->toHaveCount(189)
        ->and($view['pieces'])->toHaveKeys(['tx0', 'tx4', 'state'])
        ->and($view['pieces'])->not->toHaveKey('next')
        ->and($view['pieces']['state']['kind'])->toBe('0:0:0:37:1:00:0:-')
        ->and(json_encode($view))->not->toContain($position['queue']);
});

test('a side with nothing to set sits out, nothing falls out, and the block closes once neither can set', function () {
    $rules = new BlockspaceRules;
    // Blue's only corners are taken: after Orange's move Blue has nothing to set, and both its turns are sat out.
    $position = blockspaceWith(['a1' => 'w', 'e20' => 'b', 'd19' => 'w', 'f19' => 'w', 'd21' => 'w', 'f21' => 'w'], ['pool' => 'aaaaa', 'queue' => 'bbbbb', 'played' => ['w' => true, 'b' => true]]);
    $move = '0:b2';
    $after = $rules->apply($position, $move);

    expect($rules->legalMoves($position))->toContain($move)
        ->and($rules->notation($position, $move))->toBe('₿ I1 b2 +5 · ⚡ – · ⚡ –')
        ->and($after['last'])->toBe('-')
        ->and($after['ti'])->toBe(3)
        ->and($rules->turn($after))->toBe('w')
        ->and($rules->outcome($after, []))->toBeNull()
        // Nothing falls out for the turns sat out: the ladder is whole, the queue as before, nothing counts as a pass.
        ->and($after['pool'])->toBe('aaaab')
        ->and($after['queue'])->toBe('bbbb')
        ->and($after['passes'])->toBe(0)
        // Orange moves on in the order; the next turn is Orange's own again, so nothing is noted.
        ->and($rules->notation($after, '0:c3'))->toBe('₿ I1 c3 +5')
        ->and($rules->serialize($rules->deserialize($rules->serialize($after))))->toBe($rules->serialize($after));

    // Neither side has anything left: the block is closed at once, and the record names both.
    $full = blockspaceWith([], ['pool' => 'aaaaa', 'queue' => 'bbbbb', 'played' => ['w' => true, 'b' => true], 'board' => str_repeat('b', 189)]);
    $full['board'][(21 - 1) * 9] = 'w';
    $full['board'][(21 - 2) * 9 + 1] = '.';
    $closed = $rules->apply($full, $move);
    expect($rules->notation($full, $move))->toBe('₿ I1 b2 +5 · ⚡ – · ₿ –')
        ->and($closed['last'])->toBe('x')
        ->and($rules->legalMoves($closed))->toBe([])
        ->and($rules->outcome($closed, []))->toBe(['result' => '1-0', 'reason' => 'block_full']);

    // A move after which the other side can set notes nothing.
    $open = blockspaceWith(['a1' => 'w', 'e20' => 'b'], ['pool' => 'aaaaa', 'queue' => 'bbbbb', 'played' => ['w' => true, 'b' => true]]);
    expect($rules->notation($open, $move))->toBe('₿ I1 b2 +5');
});

test('nobody knows what is to come, only how much', function () {
    $rules = new BlockspaceRules;
    $position = $rules->deal('rapid');
    $public = $rules->withoutHidden($position);

    expect(strlen($position['pool']))->toBe(5)
        ->and($public['pool'])->toBe($position['pool'])
        ->and($public['queue'])->toBe(str_repeat('a', 37))
        ->and($public['deck'])->toBe(str_repeat('a', 42));
});

test('text that is no position or move is refused', function () {
    $rules = new BlockspaceRules;

    expect(fn () => $rules->deserialize('e1 e9'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $rules->apply($rules->start(), 'nonsense'))->toThrow(InvalidArgumentException::class);
});
