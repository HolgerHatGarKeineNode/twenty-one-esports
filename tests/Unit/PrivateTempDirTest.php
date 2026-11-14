<?php

use Tests\Support\LiveStreamFixture;

// Every test process has a temp directory of its own (tests/Support/PrivateTempDir.php): a test that lists it, as
// CasterFetchTest "leaves no file behind" does, never sees a neighbour's file.

it('gives this process a temp directory of its own, short enough for a unix socket and handed to child processes', function () {
    $dir = sys_get_temp_dir();

    expect($dir)->toEndWith('/esports-test-'.getmypid())
        ->and(is_dir($dir))->toBeTrue()
        ->and(strlen($dir.'/tos-0123456789ab/viewers.sock'))->toBeLessThan(108)
        ->and(getenv('TMPDIR'))->toBe($dir)
        ->and(trim((string) shell_exec('printf %s "$TMPDIR"')))->toBe($dir);
});

it('sees a file made in the temp directory and nothing a neighbour does in /tmp', function () {
    $listing = fn (): array => array_map('basename', glob(sys_get_temp_dir().'/*') ?: []);
    $before = $listing();
    $neighbour = '/tmp/esports-test-neighbour-'.bin2hex(random_bytes(4));
    file_put_contents($neighbour, 'x');
    $own = tempnam(sys_get_temp_dir(), 'own');

    try {
        expect(array_values(array_diff($listing(), $before)))->toBe([basename($own)]);
    } finally {
        @unlink($neighbour);
        @unlink($own);
    }
});

// The one thing that has to outlive the process: the browser tests' stream (two minutes, made with ffmpeg). Inside the
// process's own directory every pest process made it again, in the middle of the first request for a segment.
it('keeps the live stream fixture beside its temp directory, where the next process finds it', function () {
    expect(dirname(LiveStreamFixture::path()))->toBe(dirname(sys_get_temp_dir()));
});
