<?php

use Illuminate\Support\Facades\Process;

/*
 * The rules of the /live stream chat (P24, resources/js/streamChat.js and the
 * pure half of resources/js/emoji.js) run where they live:
 * tests/js/streamChat.test.mjs under Node. It fails if any Node test fails or
 * is skipped.
 */
test('stream chat: messages, zaps, emoji tags, send rules and muted runs', function () {
    $run = Process::path(base_path())
        ->timeout(60)
        ->run(['node', '--test', 'tests/js/streamChat.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 20')->toContain('ℹ skipped 0');
});
