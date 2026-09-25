<?php

namespace App\Support\TwentyOne\Stream;

use Closure;

/**
 * The public live playlist, built by the app instead of by ffmpeg.
 *
 * ffmpeg's own `append_list+discont_start` writes one EXT-X-MAP for the whole
 * list and cannot write EXT-X-DISCONTINUITY-SEQUENCE, so after a mode switch
 * old segments get the new init (docs/plans/…stream-live-scene.md). Here the
 * encoders only write into their own directories and this class keeps the
 * public window (RFC 8216):
 *
 * - a sliding window of {@see WINDOW} segments, only appended to at the end
 *   and removed from the front (§6.2.1), MEDIA-SEQUENCE counting the removed
 *   ones (§4.3.3.2), so it only ever grows, across restarts too
 * - EXT-X-DISCONTINUITY whenever the encoder run changes, and
 *   DISCONTINUITY-SEQUENCE incremented for every such tag that leaves the
 *   window (§6.2.2)
 * - EXT-X-MAP at the start and after every discontinuity (§4.3.2.5)
 * - a constant TARGETDURATION (§4.3.3.1)
 */
final class PlaylistWriter
{
    public const WINDOW = 6;

    public const TARGET_DURATION = 6;

    /**
     * Append the segments that are newer than the last one published, slide
     * the window and drop segments whose file is gone. Removal only ever
     * happens at the front, so a missing file in the middle also takes the
     * older segments with it: the window never references a missing file.
     *
     * @param  list<HlsSegment>  $available  ordered, as the current encoder lists them
     * @param  Closure(string): bool  $exists  whether the file behind a public URI exists
     */
    public function advance(PlaylistState $state, array $available, Closure $exists): PlaylistState
    {
        $window = $state->window;
        $lastUri = $state->lastUri;
        $lastRunId = $state->lastRunId;
        // Within the published run only what comes after the last published
        // segment is new; a segment of another run is always new (run ids
        // are unique per ffmpeg start). Numbers, not positions: the last
        // published segment may already have left the encoder's own list.
        $new = array_filter($available, fn (HlsSegment $segment): bool => $segment->runId !== $lastRunId
            || self::number($segment->uri) > self::number((string) $lastUri));

        foreach ($new as $segment) {
            $window[] = $segment->withDiscontinuity($lastRunId !== null && $lastRunId !== $segment->runId);
            $lastUri = $segment->uri;
            $lastRunId = $segment->runId;
        }

        $mediaSequence = $state->mediaSequence;
        $discontinuitySequence = $state->discontinuitySequence;
        $cut = max(0, count($window) - self::WINDOW);

        foreach ($window as $index => $segment) {
            if (! $exists($segment->uri) || ! $exists($segment->initUri)) {
                $cut = max($cut, $index + 1);
            }
        }

        foreach (array_slice($window, 0, $cut) as $removed) {
            $mediaSequence++;

            if ($removed->discontinuityBefore) {
                $discontinuitySequence++;
            }
        }

        return new PlaylistState($mediaSequence, $discontinuitySequence, array_slice($window, $cut), $lastUri, $lastRunId);
    }

    /**
     * The encoder's segment counter from `…-seg-000000042.m4s`, -1 if none.
     */
    private static function number(string $uri): int
    {
        return preg_match('/-seg-(\d+)\.m4s$/', $uri, $match) === 1 ? (int) $match[1] : -1;
    }

    public function render(PlaylistState $state): string
    {
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:7',
            '#EXT-X-TARGETDURATION:'.self::TARGET_DURATION,
            '#EXT-X-MEDIA-SEQUENCE:'.$state->mediaSequence,
            '#EXT-X-DISCONTINUITY-SEQUENCE:'.$state->discontinuitySequence,
            '#EXT-X-INDEPENDENT-SEGMENTS',
        ];

        foreach ($state->window as $index => $segment) {
            if ($segment->discontinuityBefore) {
                $lines[] = '#EXT-X-DISCONTINUITY';
            }

            if ($index === 0 || $segment->discontinuityBefore) {
                $lines[] = '#EXT-X-MAP:URI="'.$segment->initUri.'"';
            }

            $lines[] = sprintf('#EXTINF:%.6F,', $segment->duration);
            $lines[] = $segment->uri;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Write a file so readers only ever see the old or the new version
     * (RFC 8216 §6.2.1: playlist changes MUST be atomic).
     */
    public static function writeAtomically(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, $contents);
        rename($temporary, $path);
    }
}
