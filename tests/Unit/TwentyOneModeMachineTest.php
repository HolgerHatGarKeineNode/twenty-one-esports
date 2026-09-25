<?php

use App\Support\TwentyOne\Stream\ModeMachine;

/**
 * @param  array<int, bool>  $gameLiveAt  second => whether a live game runs
 * @return array<int, string>
 */
function modesOver(array $gameLiveAt): array
{
    $machine = new ModeMachine(60);

    return array_map(fn (bool $live, int $second): string => $machine->tick($live, $second), $gameLiveAt, array_keys($gameLiveAt));
}

test('the scene starts with a live game and the loop returns 60 s after it ended', function () {
    $modes = modesOver([0 => false, 1 => true, 2 => true, 3 => false, 61 => false, 62 => false]);

    expect($modes)->toBe(['loop', 'scene', 'scene', 'scene', 'scene', 'loop']);
});

test('a rematch within 60 s keeps the scene', function () {
    $modes = modesOver([0 => true, 10 => false, 50 => true, 100 => false, 109 => false, 110 => false]);

    expect($modes)->toBe(['scene', 'scene', 'scene', 'scene', 'scene', 'loop']);
});

test('a failed scene is kept off for a while even with a live game', function () {
    $machine = new ModeMachine(60);
    $machine->tick(true, 0);
    $machine->forceLoop(30);

    expect([$machine->mode(), $machine->tick(true, 29), $machine->tick(true, 30)])->toBe(['loop', 'loop', 'scene']);
});
