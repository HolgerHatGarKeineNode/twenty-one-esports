<?php

use App\Support\TwentyOne\Stream\Backoff;
use App\Support\TwentyOne\Stream\FfmpegCommands;

test('the loop copies the promo video with the music as sound into its own run-prefixed fMP4 HLS directory', function () {
    expect((new FfmpegCommands('/usr/bin/ffmpeg'))->hls('/srv/promo.mp4', '/srv/hls/loop/', '1790000000abcdef', '/srv/music.ffconcat'))->toBe([
        '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'warning', '-nostats',
        '-re', '-stream_loop', '-1', '-i', '/srv/promo.mp4',
        '-re', '-f', 'concat', '-safe', '0', '-stream_loop', '-1', '-i', '/srv/music.ffconcat',
        '-map', '0:v', '-map', '1:a', '-c', 'copy',
        '-f', 'hls', '-hls_time', '6', '-hls_list_size', '9', '-hls_delete_threshold', '2',
        '-hls_segment_type', 'fmp4', '-hls_fmp4_init_filename', '1790000000abcdef-init.mp4',
        '-hls_flags', 'delete_segments+omit_endlist+temp_file+independent_segments',
        '-hls_segment_filename', '/srv/hls/loop/1790000000abcdef-seg-%09d.m4s',
        '/srv/hls/loop/index.m3u8',
    ]);
});

test('the scene encodes piped stills at one per second with the music copied as sound', function () {
    expect((new FfmpegCommands('ffmpeg'))->scene('/srv/hls/scene', '1790000000abcdef', '/srv/music.ffconcat', 35))->toBe([
        'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'warning', '-nostats',
        '-probesize', '200000', '-analyzeduration', '0', '-threads', '1',
        '-use_wallclock_as_timestamps', '1', '-f', 'image2pipe', '-c:v', 'png', '-i', 'pipe:0',
        '-re', '-f', 'concat', '-safe', '0', '-stream_loop', '-1', '-i', '/srv/music.ffconcat',
        '-map', '0:v', '-map', '1:a',
        '-fps_mode', 'cfr', '-r', '1',
        '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'stillimage', '-bf', '0', '-threads', '1',
        '-x264-params', 'rc-lookahead=0:sync-lookahead=0', '-pix_fmt', 'yuv420p',
        '-crf', '35',
        '-g', '6', '-keyint_min', '6', '-sc_threshold', '0',
        '-c:a', 'copy',
        '-f', 'hls', '-hls_time', '6', '-hls_list_size', '9', '-hls_delete_threshold', '2',
        '-hls_segment_type', 'fmp4', '-hls_fmp4_init_filename', '1790000000abcdef-init.mp4',
        '-hls_flags', 'delete_segments+omit_endlist+temp_file+independent_segments',
        '-hls_segment_filename', '/srv/hls/scene/1790000000abcdef-seg-%09d.m4s',
        '/srv/hls/scene/index.m3u8',
    ]);
});

test('every encoder run gets a new id', function () {
    $ids = array_map(fn () => FfmpegCommands::newRunId(), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50)
        ->and($ids[0])->toMatch('/^\d{10}[0-9a-f]{6}$/');
});

test('the prepare encode pads the loop to the next multiple of 6 seconds', function (float $duration, int $target, string $pad) {
    $arguments = (new FfmpegCommands)->prepare('in.mp4', 'out.mp4', $duration);

    expect($arguments)->toContain('tpad=stop_mode=clone:stop_duration='.$pad)
        ->and($arguments[array_search('-t', $arguments) + 1])->toBe((string) $target)
        ->and(array_slice($arguments, array_search('-c:v', $arguments), 28))->toBe([
            '-c:v', 'libx264', '-preset', 'veryslow', '-profile:v', 'high', '-level', '3.1', '-pix_fmt', 'yuv420p',
            '-crf', '30', '-maxrate', '1000k', '-bufsize', '2000k',
            '-g', '180', '-keyint_min', '180', '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '64k', '-ar', '44100',
        ]);
})->with([
    'the promo' => [262.1, 264, '2.900'],
    'already a multiple' => [264.0, 264, '1.000'],
    'container rounding' => [264.004, 264, '0.996'],
    'just over a multiple' => [264.5, 270, '6.500'],
]);

test('the restart delay doubles up to the cap and starts over after a reset', function () {
    $backoff = new Backoff(5, 300);

    $delays = array_map(fn () => $backoff->next(), range(1, 8));
    $backoff->reset();

    expect($delays)->toBe([5, 10, 20, 40, 80, 160, 300, 300])
        ->and($backoff->next())->toBe(5);
});
