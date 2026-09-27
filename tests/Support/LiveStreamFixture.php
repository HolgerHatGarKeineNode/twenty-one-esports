<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A local HLS stream for the browser tests of the live player (P20), made
 * with ffmpeg the first time it is needed: two minutes of a test picture
 * with a tone, 256 x 144, in 2 s fMP4 segments (init.mp4 + seg-N.m4s, the
 * container the stream's supervisor writes), about 1.6 MB, in the system
 * temp directory and shared by every run and shard.
 *
 * routes/testing.php serves it under /__test/live/: the segments as files,
 * the playlist as a live window of {@see WINDOW} segments that moves on
 * every {@see SEGMENT_SECONDS} from {@see START_KEY} (no ENDLIST), so a
 * player reloads it the way it reloads the real one.
 */
final class LiveStreamFixture
{
    public const SEGMENT_SECONDS = 2;

    public const SEGMENTS = 60;

    public const WINDOW = 4;

    /** Cache key holding the unix time (float) the live window started. */
    public const START_KEY = 'test-live-start';

    /** Cache key: while true, the playlist answers 404 (the stream went away). */
    public const DOWN_KEY = 'test-live-down';

    public static function available(): bool
    {
        return Process::run(['sh', '-c', 'command -v ffmpeg'])->successful();
    }

    /**
     * The directory with init.mp4 and the segments, made once.
     */
    public static function dir(): string
    {
        $dir = sys_get_temp_dir().'/esports-live-fixture-v1';

        if (is_file($dir.'/seg-'.(self::SEGMENTS - 1).'.m4s')) {
            return $dir;
        }

        // Built next to it and renamed: two shards starting at once never see half a set.
        $building = $dir.'.'.getmypid().'.'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($building);
        $result = Process::path($building)->timeout(60)->run([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=256x144:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100',
            '-t', (string) (self::SEGMENTS * self::SEGMENT_SECONDS),
            '-map', '0:v', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-pix_fmt', 'yuv420p', '-b:v', '60k',
            '-g', (string) (10 * self::SEGMENT_SECONDS), '-keyint_min', (string) (10 * self::SEGMENT_SECONDS), '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '32k', '-ac', '2',
            '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS, '-hls_list_size', '0', '-hls_segment_type', 'fmp4',
            '-hls_fmp4_init_filename', 'init.mp4', '-hls_segment_filename', 'seg-%d.m4s', 'index.m3u8',
        ]);

        if (! $result->successful() || ! is_file($building.'/seg-'.(self::SEGMENTS - 1).'.m4s')) {
            File::deleteDirectory($building);

            throw new RuntimeException('ffmpeg could not make the live fixture: '.$result->errorOutput());
        }

        if (! @rename($building, $dir)) {
            // Another process was faster; its set is as good.
            File::deleteDirectory($building);
        }

        return $dir;
    }

    /**
     * The live playlist `$seconds` after the window started: WINDOW segments,
     * the media sequence counting up, the last window held at the end.
     */
    public static function playlist(float $seconds): string
    {
        $sequence = min(self::SEGMENTS - self::WINDOW, max(0, (int) floor($seconds / self::SEGMENT_SECONDS)));
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-TARGETDURATION:'.self::SEGMENT_SECONDS, '#EXT-X-MEDIA-SEQUENCE:'.$sequence, '#EXT-X-MAP:URI="init.mp4"'];

        for ($index = $sequence; $index < $sequence + self::WINDOW; $index++) {
            $lines[] = '#EXTINF:'.self::SEGMENT_SECONDS.'.000000,';
            $lines[] = 'seg-'.$index.'.m4s';
        }

        return implode("\n", $lines)."\n";
    }
}
