<?php

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

test('an unreadable playlist state continues from the clock floor, not from 0', function () {
    $dir = sys_get_temp_dir().'/twentyone-state-'.bin2hex(random_bytes(4));
    mkdir($dir);
    // Truncated mid-write: valid JSON never finished.
    file_put_contents($dir.'/stream.m3u8.state.json', '{"mediaSequence": 1234, "discontinuitySeq');

    $public = new PublicPlaylist($dir);

    expect($public->state()->mediaSequence)->toBeGreaterThanOrEqual(intdiv(time(), 6) - 1)
        ->and($public->state()->discontinuitySequence)->toBeGreaterThanOrEqual(intdiv(time(), 6) - 1)
        ->and($public->recoveredFrom)->toBe('unreadable');

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
