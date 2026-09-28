<?php

use Illuminate\Support\Facades\Process;

/*
 * Every board steps back through its moves like Lichess (P55): resources/js/moveHistory.js,
 * shared by the live blitz board, the daily game and the finished-game replay.
 * tests/js/moveHistory.test.mjs under Node; the boards themselves are in tests/Browser/BlitzGameTest.php
 * and tests/Browser/ChessCorrespondenceQuietTest.php.
 */
test('a board steps back through the moves, stays there while the game goes on, and follows it again at the newest move', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/moveHistory.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ skipped 0');
});
