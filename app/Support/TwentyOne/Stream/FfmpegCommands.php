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

    /** File name of each encoder's own (internal) playlist in its directory. */
    public const ENCODER_PLAYLIST = 'index.m3u8';

    /**
     * Segments each encoder keeps listed: the public window plus a margin,
     * so a segment the public playlist still references is never deleted
     * (ffmpeg deletes only after it left this list plus the delete threshold).
     */
    public const ENCODER_LIST_SIZE = PlaylistWriter::WINDOW + 3;

    /**
     * A new, unique prefix for one ffmpeg run: unix time plus randomness, so
     * a restart within the same second still gets new segment and init
     * names (players and CDNs may cache both as immutable).
     */
    public static function newRunId(): string
    {
        return time().bin2hex(random_bytes(3));
    }

    /**
     * The endless loop (zapstream.md (2)): real-time, no re-encoding, fMP4
     * HLS into the loop encoder's own directory. The promo's video is copied,
     * its own audio dropped: the music list is the sound in both modes, so the
     * audio track stays the same across a switch. Only warnings reach stderr,
     * so a process that runs for weeks does not pile up progress output.
     *
     * @return list<string>
     */
    public function hls(string $input, string $encoderDir, string $runId, string $musicList): array
    {
        return [
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'warning', '-nostats',
            '-re', '-stream_loop', '-1', '-i', $input,
            ...$this->musicInput($musicList),
            '-map', '0:v', '-map', '1:a', '-c', 'copy',
            ...$this->hlsOutput($encoderDir, $runId),
        ];
    }

    /**
     * The still-image scene: PNG frames written to stdin by the supervisor,
     * one per second, timestamped on arrival (no drift against the real-time
     * music), x264 stillimage with one IDR per 6 s segment, and the music
     * copied as is.
     *
     * @return list<string>
     */
    public function scene(string $encoderDir, string $runId, string $musicList, int $crf): array
    {
        return [
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'warning', '-nostats',
            // Probe only the first frame (the default 5 MB probe is ~50 frames,
            // i.e. ~50 s) and decode on one thread (PNG frame threading held
            // back one frame per core, ~22 s here, all measured in P3).
            '-probesize', '200000', '-analyzeduration', '0', '-threads', '1',
            '-use_wallclock_as_timestamps', '1', '-f', 'image2pipe', '-c:v', 'png', '-i', 'pipe:0',
            ...$this->musicInput($musicList),
            '-map', '0:v', '-map', '1:a',
            '-fps_mode', 'cfr', '-r', '1',
            // veryfast, not ultrafast: every segment starts with an IDR of the
            // whole scene, and ultrafast's IDRs cost 2.5x the bits. No B-frames,
            // no lookahead, one thread: at 1 fps every frame x264 holds back is
            // a second of delay (lookahead held the first segment >14 s).
            // 58 kbit/s of video at CRF 35 on 120 rendered frames (P3).
            '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'stillimage', '-bf', '0', '-threads', '1',
            '-x264-params', 'rc-lookahead=0:sync-lookahead=0', '-pix_fmt', 'yuv420p',
            '-crf', (string) $crf,
            '-g', (string) self::SEGMENT_SECONDS, '-keyint_min', (string) self::SEGMENT_SECONDS, '-sc_threshold', '0',
            '-c:a', 'copy',
            ...$this->hlsOutput($encoderDir, $runId),
        ];
    }

    /**
     * The shuffled music as an endless real-time input (see MusicPlaylist).
     *
     * @return list<string>
     */
    private function musicInput(string $musicList): array
    {
        return ['-re', '-f', 'concat', '-safe', '0', '-stream_loop', '-1', '-i', $musicList];
    }

    /**
     * @return list<string>
     */
    private function hlsOutput(string $encoderDir, string $runId): array
    {
        $encoderDir = rtrim($encoderDir, '/');

        return [
            '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS,
            '-hls_list_size', (string) self::ENCODER_LIST_SIZE, '-hls_delete_threshold', '2',
            '-hls_segment_type', 'fmp4', '-hls_fmp4_init_filename', $runId.'-init.mp4',
            '-hls_flags', 'delete_segments+omit_endlist+temp_file+independent_segments',
            '-hls_segment_filename', $encoderDir.'/'.$runId.'-seg-%09d.m4s',
            $encoderDir.'/'.self::ENCODER_PLAYLIST,
        ];
    }
}
