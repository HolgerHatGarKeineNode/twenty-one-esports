<?php

/*
| The Feature suite writes no file of the checkout's real, gitignored state.
| The stream command's cover picture (config twentyone.stream.cover.path) went
| to storage/app/stream/cover.png: a fake 4-byte "PNG" that a later browser
| test (RouteSweepTest) then found, or did not find, depending on which suite
| had run in that checkout before. Measured 2026-10-04: TwentyOneStreamTest,
| Part2 and Part3 created the file in a worktree that had none.
*/

test('the stream cover of a Feature test is not the real storage file', function () {
    expect(config('twentyone.stream.cover.path'))->not->toBe(storage_path('app/stream/cover.png'))
        ->and(config('twentyone.stream.cover.path'))->toContain('framework/testing');
});
