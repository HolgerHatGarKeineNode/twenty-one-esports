<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\FfmpegCommands;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('twentyone:stream:prepare')]
#[Description('Encode the promo once into the loop file for the 24/7 stream and verify it with ffprobe')]
class TwentyOneStreamPrepareCommand extends Command
{
    /** Tolerance for comparing measured seconds. */
    private const EPSILON = 0.05;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = config('twentyone.stream.source');
        $prepared = (string) config('twentyone.stream.prepared');

        if (! is_string($source) || ! is_file($source)) {
            $this->error('TWENTYONE_STREAM_SOURCE does not point to a readable file.');

            return self::FAILURE;
        }

        $duration = $this->duration($source);

        if ($duration === null) {
            $this->error('ffprobe could not read the duration of '.$source);

            return self::FAILURE;
        }

        $target = FfmpegCommands::padTarget($duration);
        $this->line(sprintf('Source %s: %.3f s, padding to %d s', $source, $duration, $target));

        File::ensureDirectoryExists(dirname($prepared));
        $partial = $prepared.'.part';
        $encode = Process::forever()->run(
            (new FfmpegCommands((string) config('twentyone.stream.ffmpeg')))->prepare($source, $partial, $duration),
        );

        if ($encode->failed()) {
            $this->error('ffmpeg failed: '.trim($encode->errorOutput()));
            File::delete($partial);

            return self::FAILURE;
        }

        if (! $this->verify($partial, $target)) {
            $this->error('The encoded file does not meet the loop requirements; left at '.$partial);

            return self::FAILURE;
        }

        File::move($partial, $prepared);
        $this->info(sprintf('Prepared %s (%d bytes)', $prepared, (int) filesize($prepared)));

        return self::SUCCESS;
    }

    private function duration(string $file): ?float
    {
        $probe = $this->ffprobe(['-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);

        return is_numeric(trim($probe)) ? (float) trim($probe) : null;
    }

    /**
     * Measure the encoded file and print one line per requirement.
     */
    private function verify(string $file, int $target): bool
    {
        $probe = $this->ffprobeJson([
            '-show_entries', 'stream=codec_type,codec_name,width,height,r_frame_rate,sample_rate:format=duration',
            '-of', 'json', $file,
        ]);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $video = Arr::first($streams, fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'video') ?? [];
        $audio = Arr::first($streams, fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio') ?? [];
        $duration = (float) data_get($probe, 'format.duration', 0);

        // JSON, not CSV: the CSV writer appends a separator per frame side-data section.
        $frames = $this->ffprobeJson([
            '-select_streams', 'v:0', '-skip_frame', 'nokey', '-show_entries', 'frame=pts_time', '-of', 'json', $file,
        ]);
        $keyframes = [];

        foreach (is_array($frames['frames'] ?? null) ? $frames['frames'] : [] as $frame) {
            if (is_array($frame) && is_numeric($frame['pts_time'] ?? null)) {
                $keyframes[] = (float) $frame['pts_time'];
            }
        }

        $intervals = [];

        for ($i = 1; $i < count($keyframes); $i++) {
            $intervals[] = round($keyframes[$i] - $keyframes[$i - 1], 3);
        }

        $intervals = array_values(array_unique($intervals));
        $segment = FfmpegCommands::SEGMENT_SECONDS;

        $checks = [
            'resolution' => [($video['width'] ?? '?').'x'.($video['height'] ?? '?'), ($video['width'] ?? null) === 1280 && ($video['height'] ?? null) === 720],
            'frame rate' => [$video['r_frame_rate'] ?? '?', ($video['r_frame_rate'] ?? null) === '30/1'],
            'duration' => [sprintf('%.3f s (target %d s)', $duration, $target), abs($duration - $target) < self::EPSILON && abs(fmod($duration + self::EPSILON, $segment)) < 2 * self::EPSILON],
            'keyframe interval' => [implode(', ', $intervals).' s over '.count($keyframes).' keyframes', $intervals !== [] && array_all($intervals, fn (float $interval): bool => abs($interval - $segment) < self::EPSILON)],
            'audio' => [($audio['codec_name'] ?? '?').' '.($audio['sample_rate'] ?? '?').' Hz', ($audio['codec_name'] ?? null) === 'aac' && ($audio['sample_rate'] ?? null) === '44100'],
        ];

        $allPassed = true;

        foreach ($checks as $label => [$measured, $passed]) {
            $allPassed = $allPassed && $passed;
            $this->line(sprintf('%-18s %s  %s', $label, $passed ? 'ok    ' : 'FAILED', $measured));
        }

        return $allPassed;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<mixed>
     */
    private function ffprobeJson(array $arguments): array
    {
        $decoded = json_decode($this->ffprobe($arguments), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffprobe(array $arguments): string
    {
        return Process::run([(string) config('twentyone.stream.ffprobe'), '-v', 'error', ...$arguments])->output();
    }
}
