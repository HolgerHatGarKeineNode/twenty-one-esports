<?php

/*
 * An ffmpeg stand-in for supervisor tests: takes the HLS arguments that
 * FfmpegCommands builds, writes the init file, a few segments and the
 * encoder playlist like ffmpeg's HLS muxer would, then waits for SIGTERM.
 *
 * Usage (via a shell wrapper named ffmpeg): <ffmpeg args…>
 * FAKE_ENCODER_SEGMENTS sets how many segments (default 3);
 * FAKE_ENCODER_CAPTURE, if set, is a path the public playlist (two levels
 * up: hls_dir/stream.m3u8) is copied to 1.5 s after start, so a test can
 * read what the supervisor published while it was running.
 * Per mode (LOOP or SCENE, the scene is the run reading image2pipe):
 * FAKE_ENCODER_<MODE>_SEGMENTS, _DELAY (seconds before the first segment)
 * and _EXIT_AFTER (seconds, then exit 1 as a crash).
 */

$arguments = array_slice($argv, 1);
$option = function (string $name) use ($arguments): string {
    $index = array_search($name, $arguments, true);

    return $index === false ? '' : $arguments[$index + 1];
};

$playlist = end($arguments);
$directory = dirname($playlist);
$segmentPattern = basename($option('-hls_segment_filename'));
$init = $option('-hls_fmp4_init_filename');
$mode = in_array('image2pipe', $arguments, true) ? 'SCENE' : 'LOOP';
$setting = fn (string $name, string $default): string => (string) (getenv("FAKE_ENCODER_{$mode}_{$name}") ?: getenv("FAKE_ENCODER_{$name}") ?: $default);
$count = (int) $setting('SEGMENTS', '3');
$exitAfter = (float) $setting('EXIT_AFTER', '0');
usleep((int) (1_000_000 * (float) $setting('DELAY', '0')));

file_put_contents($directory.'/'.$init, 'init');
$lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-TARGETDURATION:6', '#EXT-X-MEDIA-SEQUENCE:0', '#EXT-X-MAP:URI="'.$init.'"'];

for ($i = 0; $i < $count; $i++) {
    $segment = sprintf($segmentPattern, $i);
    file_put_contents($directory.'/'.$segment, 'segment');
    $lines[] = '#EXTINF:6.000000,';
    $lines[] = $segment;
}

file_put_contents($playlist.'.tmp', implode("\n", $lines)."\n");
rename($playlist.'.tmp', $playlist);

pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => exit(255));

if (($capture = getenv('FAKE_ENCODER_CAPTURE')) !== false && $capture !== '') {
    usleep(1_500_000);
    @copy(dirname($directory).'/stream.m3u8', $capture);
}

if ($exitAfter > 0) {
    usleep((int) (1_000_000 * $exitAfter));
    exit(1);
}

sleep(30);
