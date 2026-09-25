<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Contracts\Process\InvokedProcess;
use Symfony\Component\Process\InputStream;

/**
 * One running ffmpeg: which mode it encodes, its run id (the prefix of every
 * file it writes) and, for the scene, the stdin its PNG frames go into.
 */
final class EncoderRun
{
    public float $lastFrameAt = 0.0;

    /** @var list<string> the last stderr lines */
    public array $stderr = [];

    public function __construct(
        public readonly string $mode,
        public readonly string $runId,
        public readonly InvokedProcess $process,
        public readonly float $startedAt,
        public readonly ?InputStream $frames = null,
    ) {}

    /**
     * Whether this run's own playlist lists a segment of it yet (the moment a
     * make-before-break switch may drop the previous run).
     */
    public function hasSegment(string $hlsDir): bool
    {
        $playlist = rtrim($hlsDir, '/').'/'.$this->mode.'/'.FfmpegCommands::ENCODER_PLAYLIST;

        return is_file($playlist) && str_contains((string) @file_get_contents($playlist), $this->runId.'-seg-');
    }

    /**
     * When this run last wrote its own playlist (ffmpeg rewrites it with
     * every segment), or its start when it has not written one yet.
     */
    public function lastOutputAt(string $hlsDir): float
    {
        $playlist = rtrim($hlsDir, '/').'/'.$this->mode.'/'.FfmpegCommands::ENCODER_PLAYLIST;
        clearstatcache(true, $playlist);
        $modified = $this->hasSegment($hlsDir) ? @filemtime($playlist) : false;

        return $modified === false ? $this->startedAt : max($this->startedAt, (float) $modified);
    }

    /**
     * Queue one PNG frame for ffmpeg's stdin. Symfony writes it out whenever
     * the process is polled (running(), output reads), without blocking.
     */
    public function sendFrame(string $png, float $now): void
    {
        if ($this->frames !== null && ! $this->frames->isClosed()) {
            $this->frames->write($png);
            $this->lastFrameAt = $now;
            // A frame is ~100 kB and the pipe takes 64 kB at a time: poll a few times.
            for ($i = 0; $i < 4 && $this->process->running(); $i++) {
                usleep(2_000);
            }
        }
    }

    public function collectStderr(int $keep): void
    {
        $latest = trim($this->process->latestErrorOutput());

        if ($latest !== '') {
            $this->stderr = array_slice([...$this->stderr, ...explode("\n", $latest)], -$keep);
        }
    }
}
