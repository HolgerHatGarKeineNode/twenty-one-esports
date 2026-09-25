<?php

namespace App\Support\TwentyOne\Stream;

/**
 * The ffmpeg argument lists for the 24/7 loop, as measured in
 * docs/plans/2026-09-25T1739-twentyone-profile-stream/zapstream.md (3).
 *
 * Every list starts with the binary and is meant for Process::start() as an
 * array, so no argument passes through a shell.
 */
final class FfmpegCommands
{
    /** HLS segment length and GOP, in seconds. */
    public const SEGMENT_SECONDS = 6;

    /** Frames per GOP at 30 fps: one IDR per segment. */
    public const GOP_FRAMES = 180;

    public function __construct(private string $ffmpeg = 'ffmpeg') {}

    /**
     * Length the prepared file is padded to: the next multiple of the segment
     * length. A loop that is not a multiple produces one long segment at the
     * seam and makes EXT-X-TARGETDURATION flip (zapstream.md B.6). A few
     * milliseconds over a multiple are container rounding, not content.
     */
    public static function padTarget(float $durationSeconds): int
    {
        $segments = (int) ceil(($durationSeconds - 0.01) / self::SEGMENT_SECONDS);

        return max(1, $segments) * self::SEGMENT_SECONDS;
    }

    /**
     * One-time encode (zapstream.md (1)): x264 CRF 30 veryslow, GOP 6 s,
     * AAC 64 kbps 44.1 kHz, the last frame held and silence appended so the
     * output is exactly padTarget() long.
     *
     * @return list<string>
     */
    public function prepare(string $source, string $output, float $sourceDurationSeconds): array
    {
        $target = self::padTarget($sourceDurationSeconds);
        // Pad a second more than needed; -t cuts it to the exact target.
        $padSeconds = sprintf('%.3F', $target - $sourceDurationSeconds + 1);

        return [
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source,
            '-map', '0:v:0', '-map', '0:a:0',
            '-vf', 'tpad=stop_mode=clone:stop_duration='.$padSeconds, '-af', 'apad', '-t', (string) $target,
            '-c:v', 'libx264', '-preset', 'veryslow', '-profile:v', 'high', '-level', '3.1', '-pix_fmt', 'yuv420p',
            '-crf', '30', '-maxrate', '1000k', '-bufsize', '2000k',
            '-g', (string) self::GOP_FRAMES, '-keyint_min', (string) self::GOP_FRAMES, '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '64k', '-ar', '44100', '-ac', '2',
            '-movflags', '+faststart', '-f', 'mp4', $output,
        ];
    }

    /**
     * The endless loop (zapstream.md (2)): real-time, no re-encoding, fMP4
     * HLS with a 6-segment window. Only warnings reach stderr, so a process
     * that runs for weeks does not pile up progress output.
     *
     * @return list<string>
     */
    public function hls(string $input, string $hlsDir, string $playlistName = 'stream.m3u8'): array
    {
        $hlsDir = rtrim($hlsDir, '/');

        return [
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'warning', '-nostats',
            '-re', '-stream_loop', '-1', '-i', $input,
            '-map', '0', '-c', 'copy',
            '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS, '-hls_list_size', '6', '-hls_delete_threshold', '2',
            '-hls_segment_type', 'fmp4', '-hls_fmp4_init_filename', 'init.mp4',
            '-hls_flags', 'delete_segments+omit_endlist+temp_file+independent_segments',
            '-hls_segment_filename', $hlsDir.'/seg-%09d.m4s',
            $hlsDir.'/'.$playlistName,
        ];
    }
}
