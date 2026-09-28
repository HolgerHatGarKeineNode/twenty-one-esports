<?php

use Illuminate\Support\Facades\Process;

/*
 * Following a player (P45, resources/js/follow.js) runs in the browser, so it
 * is tested where it runs: tests/js/follow.test.mjs under Node, with fake
 * relays and throwaway keys. It fails if any Node test fails or is skipped.
 */
test('following refuses a list it could not read, keeps every follow, and checks the signed list', function () {
    $run = Process::path(base_path())
        ->timeout(60)
        ->run(['node', '--test', 'tests/js/follow.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 17')->toContain('ℹ skipped 0');
});
