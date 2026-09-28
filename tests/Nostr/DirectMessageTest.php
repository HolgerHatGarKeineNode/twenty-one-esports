<?php

use Illuminate\Support\Facades\Process;

/*
 * Player-to-player DMs (P45, resources/js/directMessage.js) are written,
 * encrypted and sent in the browser, so they are tested where they run:
 * tests/js/directMessage.test.mjs under Node. It fails if any Node test
 * fails or is skipped.
 */
test('a DM goes NIP-17 to a recipient with a DM relay list, NIP-04 to one without, and is refused when nothing fits', function () {
    $run = Process::path(base_path())
        ->timeout(60)
        ->run(['node', '--test', 'tests/js/directMessage.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 8')->toContain('ℹ skipped 0');
});
