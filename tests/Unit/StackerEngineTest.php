<?php

use Illuminate\Process\Factory;

/*
 * The Blockfill engine is plain JavaScript (resources/js/stacker) that runs in the browser
 * and in the Node verifier alike; its tests are tests/js/stacker/*.test.mjs under node:test.
 * The reference runs and how to regenerate them on purpose: tests/js/stacker/golden.test.mjs.
 */
test('the Blockfill engine replays its reference runs and keeps its rules', function () {
    $run = (new Factory)->path(dirname(__DIR__, 2))->timeout(60)->run(['node', '--test', 'tests/js/stacker']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 43')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});
