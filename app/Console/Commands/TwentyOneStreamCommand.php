<?php

namespace App\Console\Commands;

use App\Models\ChessGame;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\Stream\Backoff;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use App\Support\TwentyOne\Stream\EncoderRun;
use App\Support\TwentyOne\Stream\FfmpegCommands;
use App\Support\TwentyOne\Stream\ModeMachine;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use App\Support\TwentyOne\Stream\PlaylistWriter;
use App\Support\TwentyOne\Stream\PublicPlaylist;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\TwentyOneSigner;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;

/**
 * Foreground supervisor for the 24/7 stream, meant to run as a daemon.
 *
 * Two pictures, one at a time (ModeMachine): the promo loop, or the live-game
 * scene while a live blitz game runs (a still per second, rendered here and
 * piped to ffmpeg). Music plays under both. Each ffmpeg run writes into its
 * mode's directory; the public playlist is written by PublicPlaylist, and a
 * mode switch is make-before-break (the new encoder runs until it has its
 * first segment, then the old one stops). A crashed encoder restarts with
 * backoff.
 *
 * The stream is announced as a NIP-53 kind-30311 event: `live` only while the
 * playlist is fresh (so a dead encoder ages out in clients by itself), with
 * the game in title and summary while the scene shows one, and `ended` on
 * SIGTERM/SIGINT.
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

    /** A new encoder that has no segment after this long is given up (make before break). */
    private const SWITCH_TIMEOUT_SECONDS = 30;

    /** For sizing the music list only: the 32 tracks average ~181 s (96.5 min). */
    private const ASSUMED_TRACK_SECONDS = 180;

    private bool $stopping = false;

    private ?TwentyOneSigner $signer = null;

    /** @var list<string> */
    private array $relays = [];

    private ?int $startedAt = null;

    private int $lastCreatedAt = 0;

    /**
     * Execute the console command.
     */
    public function handle(EventBuilder $builder, RelayPublisher $publisher, SceneSource $source): int
    {
        $prepared = (string) config('twentyone.stream.prepared');
        $hlsDir = rtrim((string) config('twentyone.stream.hls_dir'), '/');
        $publicUrl = (string) config('twentyone.stream.public_url');
        $playlistName = basename((string) parse_url($publicUrl, PHP_URL_PATH));

        $problem = $this->startupProblem($prepared, $publicUrl);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->log('received signal '.$signal.', stopping');
            $this->stopping = true;
        });

        File::ensureDirectoryExists($hlsDir.'/'.ModeMachine::LOOP);
        File::ensureDirectoryExists($hlsDir.'/'.ModeMachine::SCENE);
        $this->removeLegacyLayout($hlsDir);
        $public = new PublicPlaylist($hlsDir, $playlistName);
        $playlist = $public->path();
        $renderer = SceneRenderer::fromConfig();
        $hysteresis = (int) config('twentyone.stream.scene.hysteresis_seconds', 60);
        $modes = new ModeMachine($hysteresis);
        $backoff = new Backoff(
            (int) config('twentyone.stream.backoff.initial_seconds', 5),
            (int) config('twentyone.stream.backoff.max_seconds', 300),
        );
        $republishSeconds = 60 * (int) config('twentyone.stream.republish_minutes', 20);

        $active = null;
        $pending = null;
        $nextStartAt = 0.0;
        $nextPollAt = 0.0;
        $sceneGame = null;
        $scene = null;
        $lastPublishedAt = null;
        $publishedTexts = null;
        $stopAt = is_numeric($this->option('stop-after')) ? microtime(true) + (float) $this->option('stop-after') : null;

        while (! $this->stopping) {
            $now = microtime(true);

            if ($stopAt !== null && $now >= $stopAt) {
                $this->log('--stop-after reached, stopping');

                break;
            }

            // Once a second: is a live game running, and what does the scene show?
            if ($now >= $nextPollAt) {
                $nextPollAt = $now + 1;
                $live = $source->liveGame();
                $before = $modes->mode();
                $mode = $modes->tick($live !== null, (int) $now);

                if ($mode !== $before) {
                    $this->log('mode '.$before.' -> '.$mode.($live !== null ? ' (game '.$live->number().')' : ''));
                }

                if ($mode === ModeMachine::SCENE) {
                    $sceneGame = $live ?? $source->endedGame($hysteresis) ?? $sceneGame?->fresh(['white', 'black']);
                    $scene = $sceneGame === null ? $scene : $source->scene($sceneGame, (int) ($now * 1000));
                }
            }

            $mode = $modes->mode();

            if ($active === null && $pending === null && $now >= $nextStartAt) {
                $active = $this->startEncoder($mode, $public, $hlsDir, $prepared);
            } elseif ($active !== null && $active->mode !== $mode && $pending === null && $now >= $nextStartAt) {
                // Make before break: the new encoder runs next to the old one
                // until it has written its first segment.
                $pending = $this->startEncoder($mode, $public, $hlsDir, $prepared);
            }

            foreach ([$active, $pending] as $run) {
                if ($run !== null && $run->mode === ModeMachine::SCENE && $scene !== null && $now - $run->lastFrameAt >= 1) {
                    $this->sendSceneFrame($run, $renderer, $scene, $now);
                }
            }

            if ($pending !== null) {
                $pending->collectStderr(self::STDERR_LINES);

                if (! $pending->process->running() || $now - $pending->startedAt > self::SWITCH_TIMEOUT_SECONDS) {
                    $this->log('switch to '.$pending->mode.' failed, '.($pending->process->running() ? 'no segment after '.self::SWITCH_TIMEOUT_SECONDS.' s' : 'ffmpeg exited'));
                    $this->stopEncoder($pending);
                    $pending = null;
                    $nextStartAt = $now + $backoff->next();
                } elseif ($active !== null && $pending->hasSegment($hlsDir)) {
                    $public->update($active->mode);
                    $this->stopEncoder($active);
                    $active = $pending;
                    $pending = null;
                    $this->log('switched to '.$active->mode.' run='.$active->runId);
                }
            }

            if ($active !== null) {
                $active->collectStderr(self::STDERR_LINES);

                if (! $active->process->running()) {
                    $ranSeconds = $now - $active->startedAt;
                    $this->log(sprintf('ffmpeg exited %s after %.0f s', $this->exitStatus($active), $ranSeconds));

                    foreach ($active->stderr as $line) {
                        $this->log('ffmpeg: '.$line);
                    }

                    if ($ranSeconds > self::HEALTHY_RUN_SECONDS) {
                        $backoff->reset();
                    }

                    $delay = $backoff->next();
                    $nextStartAt = $now + $delay;
                    $public->update($active->mode);
                    $active = null;
                    $this->log('restarting ffmpeg in '.$delay.' s');
                } else {
                    $public->update($active->mode);
                }
            }

            $texts = $this->texts($active?->mode === ModeMachine::SCENE ? $sceneGame : null);

            if ($this->signer !== null && $this->isFresh($playlist)
                && ($lastPublishedAt === null || time() - $lastPublishedAt >= $republishSeconds || $texts !== $publishedTexts)) {
                $this->startedAt ??= time();
                // A SIGTERM during this publish aborts it; `ended` follows below.
                $this->publish($builder, $publisher, 'live', $texts, $this->publishTimeout(), fn (): bool => $this->stopping);
                $lastPublishedAt = time();
                $publishedTexts = $texts;
            }

            usleep(250_000);
        }

        // `ended` first: a supervisor that kills us after its grace period
        // must not find it still unsent behind a slow ffmpeg shutdown.
        if ($this->signer !== null && $this->startedAt !== null) {
            $this->publish($builder, $publisher, 'ended', $this->texts(null), (float) config('twentyone.stream.shutdown_publish_seconds', 8));
        }

        foreach ([$pending, $active] as $run) {
            if ($run !== null) {
                $this->stopEncoder($run);
            }
        }

        // Nothing is running any more: do not let the web server keep serving
        // a playlist that looks live. The sequence state stays.
        $public->remove(ModeMachine::LOOP, ModeMachine::SCENE);
        $this->log('stopped');

        return self::SUCCESS;
    }

    /**
     * Start one encoder run with new names and a freshly shuffled music list.
     */
    private function startEncoder(string $mode, PublicPlaylist $public, string $hlsDir, string $prepared): EncoderRun
    {
        $ffmpeg = new FfmpegCommands((string) config('twentyone.stream.ffmpeg'));
        // New names for every run: segments and init are never reused.
        $runId = FfmpegCommands::newRunId();
        $music = $this->writeMusicList();
        $public->prune($mode);
        $pending = Process::forever()->env(ChildEnvironment::withoutSecrets());

        if ($mode === ModeMachine::SCENE) {
            $frames = new InputStream;
            $process = $pending->input($frames)->start($ffmpeg->scene($hlsDir.'/'.$mode, $runId, $music, (int) config('twentyone.stream.scene.crf', 32)));
        } else {
            $frames = null;
            $process = $pending->start($ffmpeg->hls($prepared, $hlsDir.'/'.$mode, $runId, $music));
        }

        $this->log('ffmpeg started mode='.$mode.' pid='.$process->id().' run='.$runId);

        return new EncoderRun($mode, $runId, $process, microtime(true), $frames);
    }

    /**
     * @param  array<string, mixed>  $scene
     */
    private function sendSceneFrame(EncoderRun $run, SceneRenderer $renderer, array $scene, float $now): void
    {
        try {
            $run->sendFrame($renderer->png($scene), $now);
        } catch (RuntimeException $e) {
            // Keep the stream going with the previous frame; say why once a second at most.
            $run->lastFrameAt = $now;
            $this->log('scene render failed: '.$e->getMessage());
        }
    }

    /**
     * A new random music order (MusicPlaylist), written atomically; a running
     * ffmpeg has read its list when it opened it.
     */
    private function writeMusicList(): string
    {
        $files = $this->musicFiles();
        $seconds = max(1, count($files) * self::ASSUMED_TRACK_SECONDS);
        $passes = (int) ceil(3600 * (int) config('twentyone.stream.music.list_hours', 12) / $seconds);
        $path = rtrim((string) config('twentyone.stream.scene.work_dir'), '/').'/music.ffconcat';
        File::ensureDirectoryExists(dirname($path));
        PlaylistWriter::writeAtomically($path, MusicPlaylist::ffconcat(MusicPlaylist::order($files, max(1, $passes))));

        return $path;
    }

    /**
     * @return list<string>
     */
    private function musicFiles(): array
    {
        return array_values(File::glob(rtrim((string) config('twentyone.stream.music.dir'), '/').'/*__v*.m4a'));
    }

    /**
     * Title and summary of the 30311: the configured loop texts, or the game
     * the scene shows.
     *
     * @return array{title: string, summary: string}
     */
    private function texts(?ChessGame $game): array
    {
        /** @var array{title: string, summary: string} $event */
        $event = config('twentyone.stream.event');

        if ($game === null) {
            return ['title' => $event['title'], 'summary' => $event['summary']];
        }

        $players = Str::limit($game->white->displayName(), 40, '…').' vs '.Str::limit($game->black->displayName(), 40, '…');

        return [
            'title' => 'Live now: '.$players.' · Chess Blitz',
            'summary' => $players.': live blitz chess on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Play the next game at '.config('twentyone.stream.scene.url').'. Login via Nostr.',
        ];
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

        // Both modes play the music; the scene mode can start at any second.
        try {
            MusicPlaylist::order($this->musicFiles(), 1);
        } catch (InvalidArgumentException) {
            return 'No usable music in '.config('twentyone.stream.music.dir').' (`<title>__v<n>.m4a`, at least two titles, none with more than half the files).';
        }

        $rsvg = (string) config('twentyone.stream.scene.rsvg_convert');

        if (! (str_contains($rsvg, '/') ? is_file($rsvg) && is_executable($rsvg) : (new ExecutableFinder)->find($rsvg) !== null)) {
            return 'rsvg-convert not found or not executable: '.$rsvg.' (TWENTYONE_STREAM_RSVG_CONVERT)';
        }

        $fonts = rtrim((string) config('twentyone.stream.scene.fonts_dir'), '/');

        foreach (['Unbounded-800.ttf', 'JetBrainsMono-700-latin.ttf', 'JetBrainsMono-700-latin-ext.ttf'] as $font) {
            if (! is_file($fonts.'/'.$font)) {
                return 'Scene font missing: '.$fonts.'/'.$font;
            }
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
     * @param  array{title: string, summary: string}  $texts
     * @param  (Closure(): bool)|null  $abort
     */
    private function publish(EventBuilder $builder, RelayPublisher $publisher, string $status, array $texts, float $timeoutSeconds, ?Closure $abort = null): void
    {
        assert($this->signer !== null && $this->startedAt !== null);

        /** @var array{d: string, title: string, summary: string, image: string, t?: list<string>} $stream */
        $stream = [...config('twentyone.stream.event'), ...$texts];
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
    private function stopEncoder(EncoderRun $run): void
    {
        $run->frames?->close();
        $process = $run->process;
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

        $this->log('ffmpeg stopped mode='.$run->mode.' run='.$run->runId.', '.$this->exitStatus($run));
    }

    /**
     * How the finished ffmpeg ended. Symfony throws when a process was killed
     * by a signal it did not send itself (OOM killer, `kill -9`); for a
     * supervisor that is just another exit to restart after.
     */
    private function exitStatus(EncoderRun $run): string
    {
        try {
            $status = 'code='.($run->process->wait()->exitCode() ?? '?');
        } catch (ProcessSignaledException $e) {
            $status = 'by signal '.$e->getSignal();
        }

        $run->collectStderr(self::STDERR_LINES);

        return $status;
    }

    private function isFresh(string $playlist): bool
    {
        clearstatcache(true, $playlist);
        $modifiedAt = @filemtime($playlist);

        return $modifiedAt !== false && time() - $modifiedAt < self::FRESH_SECONDS;
    }

    /**
     * Before the per-encoder directories, ffmpeg wrote `seg-*.m4s` and
     * `init.mp4` straight into hls_dir. Nothing references them any more.
     */
    private function removeLegacyLayout(string $hlsDir): void
    {
        File::delete([...File::glob($hlsDir.'/seg-*.m4s'), $hlsDir.'/init.mp4']);
    }

    private function log(string $message): void
    {
        $this->line(now()->toIso8601String().' '.$message);
    }
}
