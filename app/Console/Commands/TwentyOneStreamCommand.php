<?php

namespace App\Console\Commands;

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
use App\Support\TwentyOne\Stream\PublishSchedule;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\TwentyOneSigner;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Throwable;

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
 *
 * A stop keeps the public playlist and the segments its window references:
 * players that are still open keep a valid playlist across a daemon restart,
 * and the next start appends to it (DISCONTINUITY + MAP) once its encoder has
 * a segment. `ended` is the offline signal; `--clear` starts from nothing.
 */
#[Signature('twentyone:stream
    {--relays= : Comma-separated relay URLs, instead of twentyone.stream.relays}
    {--no-publish : Run the HLS loop without any Nostr event}
    {--stop-after= : Stop after this many seconds, exactly as on SIGTERM (local checks)}
    {--clear : Remove the public playlist and all segments before starting, instead of continuing them}')]
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

    /** Failed database polls in a row after which a scene gives way to the loop at once. */
    private const POLL_FAILURES_FOR_LOOP = 5;

    /** How long a scene that failed (render, encoder, database) stays off. */
    private const SCENE_BLOCK_SECONDS = 60;

    /** For sizing the music list only: the 32 tracks average ~181 s (96.5 min). */
    private const ASSUMED_TRACK_SECONDS = 180;

    private bool $stopping = false;

    private ?TwentyOneSigner $signer = null;

    /** @var list<string> */
    private array $relays = [];

    private ?int $startedAt = null;

    private int $lastCreatedAt = 0;

    private ?EncoderRun $active = null;

    private ?EncoderRun $pending = null;

    /** @var list<string> run ids of the encoders this process started */
    private array $runIds = [];

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
        $this->limitPollWaits();
        $public = new PublicPlaylist($hlsDir, $playlistName);
        // The instance can be reused (Artisan::call in one process): only runs of this start count.
        $this->runIds = [];

        if ($public->recoveredFrom === 'unreadable') {
            $this->log('WARNING: playlist state '.$public->statePath().' is unreadable; MEDIA-SEQUENCE continues from the clock floor '.$public->state()->mediaSequence);
        } elseif ($public->recoveredFrom === 'missing') {
            $this->log('no playlist state yet; MEDIA-SEQUENCE starts at the clock floor '.$public->state()->mediaSequence);
        }

        if ($this->option('clear')) {
            $public->remove(ModeMachine::LOOP, ModeMachine::SCENE);
            $this->log('--clear: removed the public playlist and all segments');
        } elseif ($public->recoveredFrom !== null && is_file($public->path())) {
            // Without the persisted window nothing says which files the old
            // playlist needs, and the first prune would pull them from under it.
            $public->remove(ModeMachine::LOOP, ModeMachine::SCENE);
            $this->log('removed a public playlist that has no readable state');
        }

        try {
            $this->supervise($builder, $publisher, $source, $public, $hlsDir, $prepared);
        } finally {
            // Also after an exception: `ended` goes out, the encoders stop.
            $this->shutdown($builder, $publisher, $public);
        }

        return self::SUCCESS;
    }

    private function supervise(EventBuilder $builder, RelayPublisher $publisher, SceneSource $source, PublicPlaylist $public, string $hlsDir, string $prepared): void
    {
        $renderer = SceneRenderer::fromConfig();
        $hysteresis = (int) config('twentyone.stream.scene.hysteresis_seconds', 60);
        $modes = new ModeMachine($hysteresis);
        $backoff = new Backoff(
            (int) config('twentyone.stream.backoff.initial_seconds', 5),
            (int) config('twentyone.stream.backoff.max_seconds', 300),
        );
        $schedule = new PublishSchedule(
            60 * (int) config('twentyone.stream.republish_minutes', 20),
            (int) config('twentyone.stream.text_change_seconds', 60),
        );

        // An encoder that wrote no segment for three segment lengths is restarted;
        // a scene whose renders have failed for this many wall-clock seconds gives way to the loop.
        $watchdogSeconds = (float) config('twentyone.stream.watchdog_seconds', 18);
        $renderFailureSeconds = (float) config('twentyone.stream.scene.render_failure_seconds', 10);
        $nextStartAt = 0.0;
        $nextPollAt = 0.0;
        $pollFailures = 0;
        $renderFailingSince = null;
        $sceneGame = null;
        $scene = null;
        $stopAt = is_numeric($this->option('stop-after')) ? microtime(true) + (float) $this->option('stop-after') : null;

        while (! $this->stopping) {
            $now = microtime(true);

            if ($stopAt !== null && $now >= $stopAt) {
                $this->log('--stop-after reached, stopping');

                break;
            }

            // Once a second: is a live game running, and what does the scene show?
            // A database that fails or hangs counts as "no live game": the
            // stream falls back to the loop instead of dying (FD1).
            if ($now >= $nextPollAt) {
                $nextPollAt = $now + 1;
                $before = $modes->mode();

                try {
                    $live = $source->liveGame();
                    $mode = $modes->tick($live !== null, (int) $now);

                    if ($mode === ModeMachine::SCENE) {
                        $sceneGame = $live ?? $source->endedGame($hysteresis) ?? $sceneGame?->fresh(['white', 'black']);
                        $scene = $sceneGame === null ? $scene : $source->scene($sceneGame, (int) ($now * 1000));
                    }

                    if ($pollFailures > 0) {
                        $this->log('database poll recovered after '.$pollFailures.' failed polls');
                        $pollFailures = 0;
                    }
                } catch (Throwable $e) {
                    if ($pollFailures === 0) {
                        $this->log('database poll failed, treating as no live game: '.$this->describe($e));
                    }

                    $live = null;
                    $pollFailures++;
                    $modes->tick(false, (int) $now);

                    if ($pollFailures >= self::POLL_FAILURES_FOR_LOOP && $modes->mode() === ModeMachine::SCENE) {
                        $modes->forceLoop((int) $now + self::SCENE_BLOCK_SECONDS);
                    }
                }

                if ($modes->mode() !== $before) {
                    $this->log('mode '.$before.' -> '.$modes->mode().($live !== null ? ' (game '.$live->number().')' : ''));
                }
            }

            $mode = $modes->mode();

            if ($this->active === null && $this->pending === null && $now >= $nextStartAt) {
                $this->active = $this->startEncoder($mode, $public, $hlsDir, $prepared);
            } elseif ($this->active !== null && $this->active->mode !== $mode && $this->pending === null && $now >= $nextStartAt) {
                // Make before break: the new encoder runs next to the old one
                // until it has written its first segment.
                $this->pending = $this->startEncoder($mode, $public, $hlsDir, $prepared);
            }

            // FD2: a scene that cannot be rendered for a while goes back to the loop.
            // Counted in wall-clock seconds from the start of the first failed
            // render, not in attempts: a hanging renderer makes few attempts.
            if ($renderFailingSince !== null && $now - $renderFailingSince >= $renderFailureSeconds && $modes->mode() === ModeMachine::SCENE) {
                $this->log(sprintf('scene render failing for %.0f s, back to the loop for %d s', $now - $renderFailingSince, self::SCENE_BLOCK_SECONDS));
                $modes->forceLoop((int) $now + self::SCENE_BLOCK_SECONDS);
                $renderFailingSince = null;
            }

            foreach ([$this->active, $this->pending] as $run) {
                if ($run === null || $run->mode !== ModeMachine::SCENE || $scene === null || $now - $run->lastFrameAt < 1) {
                    continue;
                }

                if ($modes->mode() !== ModeMachine::SCENE) {
                    // Being replaced by the loop: keep it fed with the last frame, no render
                    // that could hang the loop while the switch waits for the new encoder.
                    $last = $renderer->lastPng();

                    if ($last !== null) {
                        $run->sendFrame($last, $now);
                    } else {
                        $run->lastFrameAt = $now;
                    }

                    continue;
                }

                $attemptAt = microtime(true);
                $rendered = $this->sendSceneFrame($run, $renderer, $scene, $now, $renderFailingSince === null);
                $renderFailingSince = $rendered ? null : ($renderFailingSince ?? $attemptAt);
            }

            if ($this->pending !== null) {
                $this->pending->collectStderr(self::STDERR_LINES);

                if (! $this->pending->process->running() || $now - $this->pending->startedAt > self::SWITCH_TIMEOUT_SECONDS) {
                    $this->log('switch to '.$this->pending->mode.' failed, '.($this->pending->process->running() ? 'no segment after '.self::SWITCH_TIMEOUT_SECONDS.' s' : 'ffmpeg exited'));
                    $this->stopEncoder($this->pending);
                    $this->pending = null;
                    $nextStartAt = $now + $backoff->next();
                } elseif ($this->pending->hasSegment($hlsDir)) {
                    // FD3: promoted also when the old encoder died meanwhile.
                    if ($this->active !== null) {
                        $public->update($this->active->mode);
                        $this->stopEncoder($this->active);
                    }

                    $this->active = $this->pending;
                    $this->pending = null;
                    $this->log('switched to '.$this->active->mode.' run='.$this->active->runId);
                }
            }

            if ($this->active !== null) {
                $this->active->collectStderr(self::STDERR_LINES);
                $silentFor = $now - $this->active->lastOutputAt($hlsDir);

                if (! $this->active->process->running() || $silentFor > $watchdogSeconds) {
                    $ranSeconds = $now - $this->active->startedAt;

                    if ($this->active->process->running()) {
                        // FD2 watchdog: running, but no segment for three segment lengths.
                        $this->log(sprintf('ffmpeg mode=%s wrote no segment for %.0f s, restarting it', $this->active->mode, $silentFor));
                        $this->stopEncoder($this->active);

                        if ($this->active->mode === ModeMachine::SCENE) {
                            $modes->forceLoop((int) $now + self::SCENE_BLOCK_SECONDS);
                        }
                    } else {
                        $this->log(sprintf('ffmpeg exited %s after %.0f s', $this->exitStatus($this->active), $ranSeconds));

                        foreach ($this->active->stderr as $line) {
                            $this->log('ffmpeg: '.$line);
                        }
                    }

                    if ($ranSeconds > self::HEALTHY_RUN_SECONDS && $silentFor <= $watchdogSeconds) {
                        $backoff->reset();
                    }

                    $delay = $backoff->next();
                    $nextStartAt = $now + $delay;
                    $public->update($this->active->mode);
                    $this->active = null;
                    $this->log('restarting ffmpeg in '.$delay.' s');
                } else {
                    $public->update($this->active->mode);
                }
            }

            $texts = StreamTexts::for($this->active?->mode === ModeMachine::SCENE ? $sceneGame : null);

            // A playlist kept from before this start is fresh after a quick
            // restart (and rewritten when trimmed), but says nothing about
            // whether an encoder of this process works: only its segments count.
            if ($this->signer !== null && $public->hasSegmentOf($this->runIds) && $this->isFresh($public->path()) && $schedule->due($texts, time())) {
                $this->startedAt ??= time();
                // A SIGTERM during this publish aborts it; `ended` follows below.
                $this->publish($builder, $publisher, 'live', $texts, $this->publishTimeout(), fn (): bool => $this->stopping);
                $schedule->published($texts, time());
            }

            usleep(250_000);
        }
    }

    /**
     * `ended` first (a supervisor that kills us after its grace period must
     * not find it unsent behind a slow ffmpeg stop), then the encoders. The
     * public playlist and its segments stay for the next start. Best effort:
     * runs on every exit, exceptions included.
     */
    private function shutdown(EventBuilder $builder, RelayPublisher $publisher, PublicPlaylist $public): void
    {
        try {
            if ($this->signer !== null && $this->startedAt !== null) {
                $this->publish($builder, $publisher, 'ended', StreamTexts::for(null), (float) config('twentyone.stream.shutdown_publish_seconds', 8));
            }
        } catch (Throwable $e) {
            $this->log('ended not published: '.$this->describe($e));
        }

        foreach ([$this->pending, $this->active] as $run) {
            if ($run !== null) {
                try {
                    $this->stopEncoder($run);
                } catch (Throwable $e) {
                    $this->log('stopping ffmpeg failed: '.$this->describe($e));
                }
            }
        }

        $this->pending = $this->active = null;
        $this->log('stopped, keeping '.count($public->state()->window).' segments in the public playlist');
    }

    /**
     * The poll must not block the loop for long: SQLite waits for a lock
     * 60 s by default (PDO), a lost MySQL/PostgreSQL server as long as the
     * network lets it. Set short limits on the connection the poll uses.
     */
    private function limitPollWaits(): void
    {
        try {
            $connection = DB::connection();
            $milliseconds = (int) config('twentyone.stream.poll_timeout_ms', 2000);

            match ($connection->getDriverName()) {
                'sqlite' => $connection->statement('PRAGMA busy_timeout = '.$milliseconds),
                'mysql' => $connection->statement('SET SESSION max_execution_time = '.$milliseconds),
                'mariadb' => $connection->statement('SET SESSION max_statement_time = '.($milliseconds / 1000)),
                'pgsql' => $connection->statement('SET statement_timeout = '.$milliseconds),
                default => null,
            };
        } catch (Throwable $e) {
            $this->log('database poll limits not set: '.$this->describe($e));
        }
    }

    /**
     * An exception for the log: class and the first line of the message,
     * shortened (no bindings or stack, which could carry more than needed).
     */
    private function describe(Throwable $e): string
    {
        // A QueryException's message carries the SQL with its bindings; the
        // driver's own message says what went wrong without them.
        $message = $e instanceof QueryException && $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();

        return $e::class.': '.Str::limit(strtok($message, "\n") ?: '', 200);
    }

    /**
     * Start one encoder run with new names and a freshly shuffled music list.
     */
    private function startEncoder(string $mode, PublicPlaylist $public, string $hlsDir, string $prepared): EncoderRun
    {
        $ffmpeg = new FfmpegCommands((string) config('twentyone.stream.ffmpeg'));
        // New names for every run: segments and init are never reused.
        $runId = FfmpegCommands::newRunId();
        $this->runIds[] = $runId;
        $music = $this->writeMusicList();
        $public->prune($mode);
        $pending = Process::forever()->env(ChildEnvironment::withoutSecrets());

        if ($mode === ModeMachine::SCENE) {
            $frames = new InputStream;
            $process = $pending->input($frames)->start($ffmpeg->scene($hlsDir.'/'.$mode, $runId, $music, (int) config('twentyone.stream.scene.crf', 35)));
        } else {
            $frames = null;
            $process = $pending->start($ffmpeg->hls($prepared, $hlsDir.'/'.$mode, $runId, $music));
        }

        $this->log('ffmpeg started mode='.$mode.' pid='.$process->id().' run='.$runId);

        return new EncoderRun($mode, $runId, $process, microtime(true), $frames);
    }

    /**
     * Render and send one frame. When the render fails the last good frame
     * is sent again, so the picture holds instead of the encoder starving;
     * only the first failure of a series is logged.
     *
     * @param  array<string, mixed>  $scene
     * @return bool whether this frame rendered
     */
    private function sendSceneFrame(EncoderRun $run, SceneRenderer $renderer, array $scene, float $now, bool $logFailure): bool
    {
        try {
            $run->sendFrame($renderer->png($scene), $now);

            return true;
        } catch (Throwable $e) {
            $run->lastFrameAt = $now;

            if ($logFailure) {
                $this->log('scene render failed, sending the last frame again: '.$this->describe($e));
            }

            $last = $renderer->lastPng();

            if ($last !== null) {
                $run->sendFrame($last, $now);
            }

            return false;
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
     * The music files, without names that carry control characters (they
     * could not be written into the ffconcat list safely).
     *
     * @return list<string>
     */
    private function musicFiles(): array
    {
        $files = File::glob(rtrim((string) config('twentyone.stream.music.dir'), '/').'/*__v*.m4a');

        return array_values(array_filter($files, fn (string $file): bool => MusicPlaylist::isSafePath($file)));
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

        if ($this->overlaps((string) config('twentyone.stream.hls_dir'), (string) config('twentyone.stream.scene.work_dir'))) {
            return 'TWENTYONE_STREAM_HLS_DIR and the scene work dir must not contain each other (the web server serves hls_dir).';
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

        $this->relays = RelayPublisher::relayUrls($this->option('relays') ?? config('twentyone.stream.relays'));
        $invalid = array_filter($this->relays, fn (string $relay): bool => ! EventBuilder::isRelayUrl($relay));

        if ($this->relays === []) {
            return 'No relays to publish to.';
        }

        if ($invalid !== []) {
            return 'Not a ws:// or wss:// relay URL: '.implode(', ', $invalid);
        }

        return null;
    }

    /**
     * Whether one directory is the other or lies inside it (paths compared
     * resolved where they exist, textually otherwise).
     */
    private function overlaps(string $first, string $second): bool
    {
        $normalize = fn (string $path): string => rtrim(realpath($path) ?: $path, '/').'/';
        [$first, $second] = [$normalize($first), $normalize($second)];

        return str_starts_with($first, $second) || str_starts_with($second, $first);
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
