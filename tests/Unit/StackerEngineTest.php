<?php

use App\Support\Stacker\BlockfillRules;
use Illuminate\Process\Factory;

/*
 * The Blockfill engine is plain JavaScript (resources/js/stacker) that runs in the browser
 * and in the Node verifier alike; its tests are tests/js/stacker/*.test.mjs under node:test.
 * The reference runs and how to regenerate them on purpose: tests/js/stacker/golden.test.mjs.
 * 104 tests: 57 for the engine and the page logic (one of them the difficulties, one a run on a week's own rules, golden.test.mjs), 16 for the sound (P8, sound.test.mjs),
 * 9 for the sound cues the session reads around the engine (cues.test.mjs), 5 for the
 * replay viewer's seek (P5, replay-player.test.mjs), 10 for the cheat hints (P5, hints.test.mjs), 7 for the week rules (rules.test.mjs).
 */
test('the Blockfill engine replays its reference runs and keeps its rules', function () {
    $run = (new Factory)->path(dirname(__DIR__, 2))->timeout(60)->run(['node', '--test', 'tests/js/stacker']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 104')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});

test('the server and the engine read a week\'s rules alike: the same gravity curve, the same bounds and the same id for every rule set', function () {
    $sets = [
        ['goal' => 40, 'every' => 0, 'start' => 1],
        ['goal' => 60, 'every' => 0, 'start' => 3],
        ['goal' => 60, 'every' => 5, 'start' => 1, 'step' => 1, 'cap' => 9],
        ['goal' => 100, 'every' => 20, 'start' => 18, 'step' => 3, 'cap' => 19],
        ['goal' => 10, 'every' => 1, 'start' => 1, 'step' => 2, 'cap' => 2],
    ];
    $script = 'import("./resources/js/stacker/engine.js").then((e) => console.log(JSON.stringify({ levels: e.GRAVITY_LEVELS, limits: e.RULE_LIMITS, ids: '
        .json_encode($sets).'.map((r) => e.rulesId(r)) })))';
    $run = (new Factory)->path(dirname(__DIR__, 2))->timeout(30)->run(['node', '-e', $script]);
    $engine = json_decode($run->output(), true);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($engine['levels'])->toBe(BlockfillRules::GRAVITY_LEVELS)
        ->and($engine['limits'])->toBe(BlockfillRules::LIMITS)
        ->and($engine['ids'])->toBe(array_map(BlockfillRules::id(...), $sets))
        ->and($engine['ids'])->toBe(['bf1', 't60g3', 't60e5g1s1c9', 't100e20g18s3c19', 't10e1g1s2c2']);
});
