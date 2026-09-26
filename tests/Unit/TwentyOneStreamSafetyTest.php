<?php

use App\Support\TwentyOne\Stream\PlaylistState;
use App\Support\TwentyOne\Stream\PublicName;
use App\Support\TwentyOne\Stream\PublicPlaylist;
use App\Support\TwentyOne\Stream\PublishSchedule;

test('public names lose control, bidi and zero-width characters and keep one space per gap', function () {
    expect(PublicName::clean("  Mallory\u{202E}gnp.exe\nline\ttwo\u{200B}\u{2066}x  "))->toBe('Mallory gnp.exe line two x')
        ->and(PublicName::limit('Wilhelmine von Hohenzollern', 10))->toBe('Wilhelmin…');
});

test('a text change is republished at most once a minute, the first live at once', function () {
    $schedule = new PublishSchedule(1200, 60);
    $loop = ['title' => 'loop', 'summary' => 'loop'];
    $game = ['title' => 'game', 'summary' => 'game'];

    $due = [$schedule->due($loop, 0)];
    $schedule->published($loop, 0);
    $due[] = $schedule->due($loop, 30);
    $due[] = $schedule->due($game, 59);
    $due[] = $schedule->due($game, 60);
    $schedule->published($game, 60);
    $due[] = $schedule->due($game, 1259);
    $due[] = $schedule->due($game, 1260);

    expect($due)->toBe([true, false, false, true, false, true]);
});

test('a missing or unreadable state continues above both counters, with a small discontinuity floor', function (?string $json, string $why) {
    // 2026-09-26T12:00:00Z: 268.5 days (386640 minutes) after 2026-01-01T00:00Z.
    $now = 1790424000;
    [$state, $recoveredFrom] = PlaylistState::recover($json, $now);

    expect($recoveredFrom)->toBe($why)
        // One slot per 2 s: faster than segments are ever published (6 s each).
        ->and($state->mediaSequence)->toBe(895212000)
        // Minutes since 2026: hls.js mistimes playback from ~1e8 on (measured), so it stays far below.
        ->and($state->discontinuitySequence)->toBe(386640)
        ->and($state->discontinuitySequence)->toBeLessThan(50_000_000);
})->with([
    // The prod hls_dir has no state file yet: the first start takes this path.
    'missing (first start)' => [null, 'missing'],
    'truncated mid-write' => ['{"mediaSequence": 1234, "discontinuitySeq', 'unreadable'],
    'empty' => ['', 'unreadable'],
]);

test('the discontinuity floor stays below 5e7 even far in the future', function () {
    [$state] = PlaylistState::recover(null, 4102444800); // 2100-01-01

    expect($state->discontinuitySequence)->toBeLessThan(50_000_000)->toBeGreaterThan(0);
});

test('an unreadable state file on disk is recovered, not reset to 0', function () {
    $dir = sys_get_temp_dir().'/twentyone-state-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/stream.m3u8.state.json', '{"mediaSequence": 1234, "discontinuitySeq');

    $public = new PublicPlaylist($dir);

    expect($public->state()->mediaSequence)->toBeGreaterThanOrEqual(intdiv(time(), 2) - 1)
        ->and($public->recoveredFrom)->toBe('unreadable');

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
