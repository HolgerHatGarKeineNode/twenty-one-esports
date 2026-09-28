<?php

use Illuminate\Support\Facades\Process;

/*
 * The casual room countdown (resources/js/casualClock.js) counts to the
 * server's deadline on a device whose clock runs off: tests/js/casualClock.test.mjs
 * under Node. The room passes the server time (CasualPlayTest).
 */
test('the casual room countdown corrects the device clock by the server offset', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/casualClock.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ fail 0');
});
