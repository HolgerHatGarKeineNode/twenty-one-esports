<?php

use Illuminate\Process\Factory;

/*
 * The Blockfill engine is plain JavaScript (resources/js/stacker) that runs in the browser
 * and in the Node verifier alike; its tests are tests/js/stacker/*.test.mjs under node:test.
 * The reference runs and how to regenerate them on purpose: tests/js/stacker/golden.test.mjs.
 * 93 tests: 55 for the engine and the page logic, 16 for the sound (P8, sound.test.mjs),
 * 9 for the sound cues the session reads around the engine (cues.test.mjs), 5 for the
 * replay viewer's seek (P5, replay-player.test.mjs), 8 for the cheat hints (P5, hints.test.mjs).
 */
test('the Blockfill engine replays its reference runs and keeps its rules', function () {
    $run = (new Factory)->path(dirname(__DIR__, 2))->timeout(60)->run(['node', '--test', 'tests/js/stacker']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 93')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});
