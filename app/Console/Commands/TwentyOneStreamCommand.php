<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\Stream\Backoff;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use App\Support\TwentyOne\Stream\FfmpegCommands;
use App\Support\TwentyOne\TwentyOneSigner;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Foreground supervisor for the 24/7 HLS loop, meant to run as a daemon.
 *
 * It keeps one ffmpeg alive (restart with backoff) and announces the stream
 * as a NIP-53 kind-30311 event: `live` only while the playlist is fresh, so a
 * dead encoder stops being re-announced and ages out in clients by itself,
 * and `ended` on SIGTERM/SIGINT.
 */
#[Signature('twentyone:stream
    {--relays= : Comma-separated relay URLs, instead of twentyone.relays.public}
    {--no-publish : Run the HLS loop without any Nostr event}
    {--stop-after= : Stop after this many seconds, exactly as on SIGTERM (local checks)}')]
#[Description('Run the TWENTY ONE 24/7 HLS loop and announce it as a NIP-53 live event')]
class TwentyOneStreamCommand extends Command
{
    /** A playlist younger than this counts as a running stream. */
    private const FRESH_SECONDS = 20;

    /** An ffmpeg run longer than this resets the restart backoff. */
    private const HEALTHY_RUN_SECONDS = 60;

    /** How many stderr lines of a failed ffmpeg run are logged. */
    private const STDERR_LINES = 10;

    private bool $stopping = false;

    private ?TwentyOneSigner $signer = null;

    /** @var list<string> */
    private array $relays = [];

    private ?int $startedAt = null;

    private int $lastCreatedAt = 0;

    /** @var list<string> */
    private array $stderr = [];

    /**
     * Execute the console command.
     */
    public function handle(EventBuilder $builder, RelayPublisher $publisher): int
    {
        $prepared = (string) config('twentyone.stream.prepared');
        $hlsDir = rtrim((string) config('twentyone.stream.hls_dir'), '/');
        $publicUrl = (string) config('twentyone.stream.public_url');
        $playlistName = basename((string) parse_url($publicUrl, PHP_URL_PATH));
        $playlist = $hlsDir.'/'.$playlistName;

        $problem = $this->startupProblem($prepared, $publicUrl);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->log('received signal '.$signal.', stopping');
            $this->stopping = true;
        });

        File::ensureDirectoryExists($hlsDir);
        $arguments = (new FfmpegCommands((string) config('twentyone.stream.ffmpeg')))->hls($prepared, $hlsDir, $playlistName);
        $backoff = new Backoff(
            (int) config('twentyone.stream.backoff.initial_seconds', 5),
            (int) config('twentyone.stream.backoff.max_seconds', 300),
        );
        $republishSeconds = 60 * (int) config('twentyone.stream.republish_minutes', 20);

        $process = null;
        $runStartedAt = 0.0;
        $nextStartAt = 0.0;
        $lastPublishedAt = null;
        $stopAt = is_numeric($this->option('stop-after')) ? microtime(true) + (float) $this->option('stop-after') : null;

        while (! $this->stopping) {
            if ($stopAt !== null && microtime(true) >= $stopAt) {
                $this->log('--stop-after reached, stopping');

                break;
            }

            if ($process === null && microtime(true) >= $nextStartAt) {
                $this->clearHlsDir($hlsDir, $playlistName);
                $process = Process::forever()->env(ChildEnvironment::withoutSecrets())->start($arguments);
                $runStartedAt = microtime(true);
                $this->stderr = [];
                $this->log('ffmpeg started pid='.$process->id());
            }

            if ($process !== null) {
                $this->collectStderr($process);

                if (! $process->running()) {
                    $ranSeconds = microtime(true) - $runStartedAt;
                    $exitStatus = $this->exitStatus($process);
                    $this->log(sprintf('ffmpeg exited %s after %.0f s', $exitStatus, $ranSeconds));

                    foreach ($this->stderr as $line) {
                        $this->log('ffmpeg: '.$line);
                    }

                    if ($ranSeconds > self::HEALTHY_RUN_SECONDS) {
                        $backoff->reset();
                    }

                    $delay = $backoff->next();
                    $nextStartAt = microtime(true) + $delay;
                    $process = null;
                    $this->log('restarting ffmpeg in '.$delay.' s');
                }
            }

            if ($this->signer !== null && $this->isFresh($playlist)
                && ($lastPublishedAt === null || time() - $lastPublishedAt >= $republishSeconds)) {
                $this->startedAt ??= time();
                // A SIGTERM during this publish aborts it; `ended` follows below.
                $this->publish($builder, $publisher, 'live', $this->publishTimeout(), fn (): bool => $this->stopping);
                $lastPublishedAt = time();
            }

            usleep(500_000);
        }

        // `ended` first: a supervisor that kills us after its grace period
        // must not find it still unsent behind a slow ffmpeg shutdown.
        if ($this->signer !== null && $this->startedAt !== null) {
            $this->publish($builder, $publisher, 'ended', (float) config('twentyone.stream.shutdown_publish_seconds', 8));
        }

        if ($process !== null) {
            $this->stopProcess($process);
        }

        // Nothing is looping any more: do not let the web server keep serving
        // a playlist that looks live.
        $this->clearHlsDir($hlsDir, $playlistName);
        $this->log('stopped');

        return self::SUCCESS;
    }

    /**
     * Everything that would otherwise fail only after ffmpeg is running
     * (and then again after every restart), checked before anything starts.
     */
    private function startupProblem(string $prepared, string $publicUrl): ?string
    {
        if (! EventBuilder::isStreamingUrl($publicUrl)) {
            return 'TWENTYONE_STREAM_URL must be an http(s) URL ending in .m3u8 with nothing after it: '.$publicUrl;
        }

        if (! is_file($prepared)) {
            return 'Prepared stream file not found: '.$prepared.'. Run `php artisan twentyone:stream:prepare` first.';
        }

        $ffmpeg = (string) config('twentyone.stream.ffmpeg');
        $resolvable = str_contains($ffmpeg, '/') ? is_file($ffmpeg) && is_executable($ffmpeg) : (new ExecutableFinder)->find($ffmpeg) !== null;

        if (! $resolvable) {
            return 'ffmpeg not found or not executable: '.$ffmpeg.' (TWENTYONE_STREAM_FFMPEG)';
        }

        if ($this->option('no-publish')) {
            return null;
        }

        try {
            $this->signer = TwentyOneSigner::fromConfig();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->relays = RelayPublisher::relayUrls($this->option('relays') ?? config('twentyone.relays.public'));
        $invalid = array_filter($this->relays, fn (string $relay): bool => ! EventBuilder::isRelayUrl($relay));

        if ($this->relays === []) {
            return 'No relays to publish to.';
        }

        if ($invalid !== []) {
            return 'Not a ws:// or wss:// relay URL: '.implode(', ', $invalid);
        }

        return null;
    }

    private function publishTimeout(): float
    {
        return (float) config('twentyone.nostr.publish_timeout_seconds', 5);
    }

    /**
     * @param  (Closure(): bool)|null  $abort
     */
    private function publish(EventBuilder $builder, RelayPublisher $publisher, string $status, float $timeoutSeconds, ?Closure $abort = null): void
    {
        assert($this->signer !== null && $this->startedAt !== null);

        /** @var array{d: string, title: string, summary: string, image: string, t?: list<string>} $stream */
        $stream = config('twentyone.stream.event');
        // A replaceable event only replaces an older one: never the same second.
        $createdAt = max(time(), $this->lastCreatedAt + 1);
        $unsigned = $builder->liveActivity(
            $stream,
            (string) config('twentyone.stream.public_url'),
            $this->signer->pubkey,
            $status,
            $this->startedAt,
            $status === 'ended' ? $createdAt : null,
        )->setCreatedAt($createdAt);
        $event = $this->signer->sign($unsigned);
        $this->lastCreatedAt = $createdAt;

        $results = $publisher->publish($event, $this->relays, $timeoutSeconds, $abort);
        $summary = collect($results)->map(fn ($result): string => $result->relay.' '.($result->accepted ? 'ok' : 'failed: '.$result->message));

        $this->log(sprintf(
            'published kind 30311 status=%s id=%s created_at=%d to %d/%d relays (%s)',
            $status,
            $event['id'],
            $event['created_at'],
            collect($results)->where('accepted', true)->count(),
            count($results),
            $summary->implode('; '),
        ));
    }

    /**
     * SIGTERM, and SIGKILL if ffmpeg is still there after 2 s (it normally
     * exits at once; the whole shutdown has to fit a ~10 s grace period).
     */
    private function stopProcess(InvokedProcess $process): void
    {
        $deadline = microtime(true) + 2;

        if ($process->running()) {
            $process->signal(SIGTERM);
        }

        while ($process->running() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if ($process->running()) {
            $process->signal(SIGKILL);
        }

        $this->log('ffmpeg stopped, '.$this->exitStatus($process));
    }

    /**
     * How the finished ffmpeg ended. Symfony throws when a process was killed
     * by a signal it did not send itself (OOM killer, `kill -9`); for a
     * supervisor that is just another exit to restart after.
     */
    private function exitStatus(InvokedProcess $process): string
    {
        try {
            $status = 'code='.($process->wait()->exitCode() ?? '?');
        } catch (ProcessSignaledException $e) {
            $status = 'by signal '.$e->getSignal();
        }

        $this->collectStderr($process);

        return $status;
    }

    private function isFresh(string $playlist): bool
    {
        clearstatcache(true, $playlist);
        $modifiedAt = @filemtime($playlist);

        return $modifiedAt !== false && time() - $modifiedAt < self::FRESH_SECONDS;
    }

    /**
     * Remove what a previous ffmpeg run left behind: its numbering restarts,
     * so old segments would otherwise never be deleted. Only our own file
     * names are touched.
     */
    private function clearHlsDir(string $hlsDir, string $playlistName): void
    {
        File::delete([
            ...File::glob($hlsDir.'/seg-*.m4s'),
            ...File::glob($hlsDir.'/'.$playlistName.'*'),
            $hlsDir.'/init.mp4',
        ]);
    }

    /**
     * Keep only the last stderr lines of the running ffmpeg.
     */
    private function collectStderr(InvokedProcess $process): void
    {
        $latest = trim($process->latestErrorOutput());

        if ($latest === '') {
            return;
        }

        $this->stderr = array_slice([...$this->stderr, ...explode("\n", $latest)], -self::STDERR_LINES);
    }

    private function log(string $message): void
    {
        $this->line(now()->toIso8601String().' '.$message);
    }
}
