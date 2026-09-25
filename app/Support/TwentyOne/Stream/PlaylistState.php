<?php

namespace App\Support\TwentyOne\Stream;

/**
 * What the public playlist has published so far; persisted as JSON next to
 * it so MEDIA-SEQUENCE and DISCONTINUITY-SEQUENCE survive daemon restarts
 * (RFC 8216 §6.2.2: neither may ever go down for the same playlist URL).
 *
 * `mediaSequence` is the sequence number of window[0]; `lastUri` is the
 * newest segment ever appended, so a tick only appends what came after it,
 * and `lastRunId` its run, so a new run is a discontinuity even when the
 * old window is gone (its files deleted while the daemon was down).
 */
final readonly class PlaylistState
{
    /**
     * @param  list<HlsSegment>  $window
     */
    public function __construct(
        public int $mediaSequence = 0,
        public int $discontinuitySequence = 0,
        public array $window = [],
        public ?string $lastUri = null,
        public ?string $lastRunId = null,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'mediaSequence' => $this->mediaSequence,
            'discontinuitySequence' => $this->discontinuitySequence,
            'lastUri' => $this->lastUri,
            'lastRunId' => $this->lastRunId,
            'window' => array_map(fn (HlsSegment $segment): array => $segment->toArray(), $this->window),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * The persisted state, or a fresh one when there is none. A damaged file
     * must not reset the sequence numbers silently to 0 while an old window
     * is still cached by players, so it keeps the counters it can read and
     * starts a new window on top of them.
     */
    public static function fromJson(?string $json): self
    {
        $data = $json === null ? null : json_decode($json, true);

        if (! is_array($data)) {
            return new self;
        }

        $window = [];

        foreach (is_array($data['window'] ?? null) ? $data['window'] : [] as $item) {
            $segment = HlsSegment::fromArray($item);

            if ($segment === null) {
                $window = [];

                break;
            }

            $window[] = $segment;
        }

        $mediaSequence = is_int($data['mediaSequence'] ?? null) ? $data['mediaSequence'] : 0;
        $discontinuitySequence = is_int($data['discontinuitySequence'] ?? null) ? $data['discontinuitySequence'] : 0;
        $lastUri = is_string($data['lastUri'] ?? null) ? $data['lastUri'] : null;
        $lastRunId = is_string($data['lastRunId'] ?? null) ? $data['lastRunId'] : null;

        return new self($mediaSequence, $discontinuitySequence, $window, $lastUri, $lastRunId);
    }
}
