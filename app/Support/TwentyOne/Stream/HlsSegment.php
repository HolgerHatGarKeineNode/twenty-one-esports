<?php

namespace App\Support\TwentyOne\Stream;

/**
 * One media segment as the public playlist references it.
 *
 * `uri` and `initUri` are relative to the public playlist (e.g.
 * `loop/1790353930a1b2-seg-000000003.m4s`). `runId` names the encoder run
 * that wrote it; a change of run is a discontinuity (new init, new
 * timestamps). `discontinuityBefore` is decided by {@see PlaylistWriter}.
 */
final readonly class HlsSegment
{
    public function __construct(
        public string $uri,
        public float $duration,
        public string $initUri,
        public string $runId,
        public bool $discontinuityBefore = false,
    ) {}

    public function withDiscontinuity(bool $discontinuityBefore): self
    {
        return new self($this->uri, $this->duration, $this->initUri, $this->runId, $discontinuityBefore);
    }

    /**
     * @return array{uri: string, duration: float, initUri: string, runId: string, discontinuityBefore: bool}
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'duration' => $this->duration,
            'initUri' => $this->initUri,
            'runId' => $this->runId,
            'discontinuityBefore' => $this->discontinuityBefore,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['uri'] ?? null) || ! is_numeric($data['duration'] ?? null)
            || ! is_string($data['initUri'] ?? null) || ! is_string($data['runId'] ?? null)) {
            return null;
        }

        return new self($data['uri'], (float) $data['duration'], $data['initUri'], $data['runId'], ($data['discontinuityBefore'] ?? false) === true);
    }
}
