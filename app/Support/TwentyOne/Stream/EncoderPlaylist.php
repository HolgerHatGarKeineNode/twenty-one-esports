<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Reads the internal playlist one ffmpeg run writes into its own directory
 * and turns it into segments addressed from the public playlist.
 *
 * Only what the playlist itself states is used: EXTINF durations, the
 * EXT-X-MAP init and the segment URIs. The run id is the file name prefix
 * `<runId>-seg-…` that {@see FfmpegCommands} gives every run.
 */
final class EncoderPlaylist
{
    /**
     * @param  string  $directory  the encoder directory relative to the public playlist, e.g. `loop`
     * @return list<HlsSegment>
     */
    public static function parse(string $text, string $directory): array
    {
        $directory = trim($directory, '/');
        $segments = [];
        $init = null;
        $duration = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^#EXT-X-MAP:URI="([^"]+)"/', $line, $match) === 1) {
                $init = $match[1];
            } elseif (preg_match('/^#EXTINF:([0-9.]+)/', $line, $match) === 1) {
                $duration = (float) $match[1];
            } elseif ($line !== '' && ! str_starts_with($line, '#') && $duration !== null && $init !== null) {
                if (preg_match('/^([A-Za-z0-9]+)-seg-\d+\.m4s$/', $line, $match) === 1) {
                    $segments[] = new HlsSegment($directory.'/'.$line, $duration, $directory.'/'.$init, $match[1]);
                }

                $duration = null;
            }
        }

        return $segments;
    }
}
