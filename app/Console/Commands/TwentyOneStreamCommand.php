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
use App\Support\TwentyOne\Stream\PublishSchedule;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamCover;
use App\Support\TwentyOne\Stream\StreamSession;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\TwentyOne\Stream\ViewerFeed;
use App\Support\TwentyOne\TwentyOneSigner;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
 * scene while any chess game is active (a still per second, rendered here and
 * piped to ffmpeg). Music plays under both. Each ffmpeg run writes into its
 * mode's directory; the public playlist is written by PublicPlaylist, and a
 * mode switch is make-before-break (the new encoder runs until it has its
 * first segment, then the old one stops). A crashed encoder restarts with
 * backoff.
 *
 * The stream is announced as a NIP-53 kind-30311 event: `live` only while the
 * playlist is fresh (so a dead encoder ages out in clients by itself), with
 * the game in title and summary while the scene shows one. SIGTERM/SIGINT
 * (every deploy restarts the daemon) publishes nothing: the `live` event
 * stays, NIP-53 lets clients treat it as ended after an hour without update,
 * and the next start continues the same session (StreamSession: the same
 * `starts`, so zap.stream keeps showing the chat). A permanent stop is
 * `twentyone:stream:end`, which publishes `ended` and clears the session.
 *
 * Viewers are counted from nginx's playlist access log, sent as syslog
 * datagrams to a unix socket this process binds (ViewerSocket, config
 * twentyone.stream.viewers; the nginx lines are there). The count reaches
 * every scene as `viewers` and the `live` 30311 as `current_participants`;
 * it is null, and the stream runs on without it, while the socket cannot be
 * bound or read; ViewerFeed binds it again after a backoff.
 *
 * A stop keeps the public playlist and the segments its window references:
 * players that are still open keep a valid playlist across a daemon restart,
 * and the next start appends to it (DISCONTINUITY + MAP) once its encoder has
 * a segment. `--clear` starts from nothing.
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

    /** Cache key of what the stream announces (viewers, title, summary), read by the website. */
    public const ANNOUNCE_CACHE_KEY = 'twentyone.stream.announced';

    private const ANNOUNCE_TTL_SECONDS = 60;

    private const ANNOUNCE_REFRESH_SECONDS = 20;

    /** For sizing the music list only: the 32 tracks average ~181 s (96.5 min). */
    private const ASSUMED_TRACK_SECONDS = 180;

    private bool $stopping = false;

    private ?TwentyOneSigner $signer = null;

    /** @var list<string> */
    private array $relays = [];

    /** `starts` of the session this run announces, set with its first `live`. */
    private ?int $startedAt = null;

    /** `starts` of a session a recent run left (StreamSession), continued by the first `live`. */
    private ?int $resumedStarts = null;

    private ?StreamSession $session = null;

    private int $lastCreatedAt = 0;

    private ?EncoderRun $active = null;

    private ?EncoderRun $pending = null;

    /** Whether the rotation scene failed on the last frame (logged once per series). */
    private bool $rotationFailing = false;

    /** Whether the upcoming tournaments could not be read on the last poll (logged once per series). */
    private bool $tournamentsFailing = false;

    /** Whether the tournament slides could not be built on the last frame (logged once per series). */
    private bool $tournamentFramesFailing = false;

    /** Whether the announced state could not be cached last time (logged once per series). */
    private bool $announceCacheFailing = false;

    /** @var array{viewers: int|null, title: string, summary: string}|null the state last put into the cache */
    private ?array $cachedAnnouncement = null;

    private float $announcementCachedAt = 0.0;

    /** The viewer count from the socket nginx logs playlist requests to; null outside a run. */
    private ?ViewerFeed $viewers = null;

    /** @var list<string> run ids of the encoders this process started */
    private array $runIds = [];

    /**
     * Execute the console command.
     */
    public function handle(EventBuilder $builder, RelayPublisher $publisher, SceneSource $source, StreamStats $counts, TournamentSlides $slides): int
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
        $this->startedAt = null;
        $this->resumedStarts = null;
        $this->session = null;

        if ($this->signer !== null) {
            $this->session = StreamSession::fromConfig();
            $this->resumedStarts = $this->session->resumableStarts(time(), 60 * max(0, (int) config('twentyone.stream.session_resume_minutes', 30)), $sessionProblem);

            if ($sessionProblem !== null) {
                $this->log('session file '.$this->session->path().' '.$sessionProblem.', starting a new session');
            } elseif ($this->resumedStarts !== null) {
                $this->log('continuing the live session of '.$this->resumedStarts.' (starts)');
            }
        }
        $this->rotationFailing = false;
        $this->tournamentsFailing = false;
        $this->tournamentFramesFailing = false;
        $this->announceCacheFailing = false;
        $this->cachedAnnouncement = null;
        $this->announcementCachedAt = 0.0;
        $this->viewers = null;

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
            $this->supervise($builder, $publisher, $source, $counts, $slides, $public, $hlsDir, $prepared);
        } finally {
            // Also after an exception: the encoders stop.
            $this->shutdown($public);
        }

        return self::SUCCESS;
    }

    private function supervise(EventBuilder $builder, RelayPublisher $publisher, SceneSource $source, StreamStats $counts, TournamentSlides $slides, PublicPlaylist $public, string $hlsDir, string $prepared): void
    {
        $renderer = SceneRenderer::fromConfig();
        // Games that ended within this window stay on show with their result.
        $hysteresis = (int) config('twentyone.stream.scene.hysteresis_seconds', 60);
        // The planner decides scene or loop; the machine only keeps a failed scene off.
        $modes = new ModeMachine(0);
        $planner = RotationPlanner::fromConfig($this->promoSeconds($prepared));
        $backoff = new Backoff(
            (int) config('twentyone.stream.backoff.initial_seconds', 5),
            (int) config('twentyone.stream.backoff.max_seconds', 300),
        );
        $schedule = new PublishSchedule(
            60 * (int) config('twentyone.stream.republish_minutes', 20),
            (int) config('twentyone.stream.text_change_seconds', 60),
        );
        $cover = StreamCover::fromConfig();

        // An encoder that wrote no segment for three segment lengths is restarted;
        // a scene whose renders have failed for this many wall-clock seconds gives way to the loop.
        $watchdogSeconds = (float) config('twentyone.stream.watchdog_seconds', 18);
        $renderFailureSeconds = (float) config('twentyone.stream.scene.render_failure_seconds', 10);
        $nextStartAt = 0.0;
        $nextPollAt = 0.0;
        $pollFailures = 0;
        $renderFailingSince = null;
        /** @var list<ChessGame> $sceneGames */
        $sceneGames = [];
        $sceneMore = 0;
        /** @var array<string, mixed> $stats the last counts that could be read */
        $stats = [];
        /** @var list<array<string, mixed>> $tournamentSnapshots the last upcoming tournaments that could be read */
        $tournamentSnapshots = [];
        $slotKey = null;
        /** @var array{view: view-string, data: array<string, mixed>, fallback: array<string, mixed>|null, label: string, announce: bool}|null $frame */
        $frame = null;
        $this->viewers = ViewerFeed::fromConfig($this->log(...));
        $viewerLimit = max(1, (int) config('twentyone.stream.viewers.max_datagrams_per_tick', 2000));
        $stopAt = is_numeric($this->option('stop-after')) ? microtime(true) + (float) $this->option('stop-after') : null;

        while (! $this->stopping) {
            $now = microtime(true);

            if ($stopAt !== null && $now >= $stopAt) {
                $this->log('--stop-after reached, stopping');

                break;
            }

            $viewerCount = $this->viewers->count((int) $now, $viewerLimit);

            // Once a second: which games are on show (live ones, daily included,
            // and those that just ended), which rotation slot runs, and its data.
            // A database that fails or hangs counts as "no game": the rotation
            // goes on with the teasers and the last counts instead of dying (FD1).
            if ($now >= $nextPollAt) {
                $nextPollAt = $now + 1;
                $before = $modes->mode();

                try {
                    ['games' => $sceneGames, 'more' => $sceneMore] = $source->sceneGames($hysteresis);

                    if ($pollFailures > 0) {
                        $this->log('database poll recovered after '.$pollFailures.' failed polls');
                        $pollFailures = 0;
                    }
                } catch (Throwable $e) {
                    if ($pollFailures === 0) {
                        $this->log('database poll failed, treating as no live game: '.$this->describe($e));
                    }

                    [$sceneGames, $sceneMore] = [[], 0];
                    $pollFailures++;

                    if ($pollFailures >= self::POLL_FAILURES_FOR_LOOP && $modes->mode() === ModeMachine::SCENE) {
                        $modes->forceLoop((int) $now + self::SCENE_BLOCK_SECONDS);
                    }
                }

                // Upcoming tournaments (cached like the counts); their countdown ticks with this poll.
                $tournamentSnapshots = $this->readTournaments($slides, $tournamentSnapshots, $pollFailures === 0);
                $tournaments = $this->tournamentFrames($slides, $tournamentSnapshots, (int) ($now * 1000));

                if ($cover->due($now)) {
                    $stats = $this->readStats($counts, $stats, $pollFailures === 0);
                    $this->advanceCover($cover, $source, $tournaments, $sceneGames, $sceneMore, $stats, $now);
                }

                $slot = $planner->at($now, array_map(fn (ChessGame $game): array => ['id' => $game->id, 'blitz' => ! $game->isCorrespondence()], $sceneGames), array_column($tournaments, 'id'));
                $modes->tick($slot['kind'] !== RotationPlanner::LOOP, (int) $now);

                if ($slot['scene'] !== null) {
                    $stats = $this->readStats($counts, $stats, $pollFailures === 0);
                    $frame = $this->frameFor($source, $slot, $sceneGames, $sceneMore, (int) ($now * 1000), $stats, $frame, $tournaments, $viewerCount);
                }

                $key = $slot['kind'].':'.$slot['scene'].':'.$slot['gameId'].':'.$slot['tournamentId'].':'.$slot['until'];

                if ($key !== $slotKey) {
                    $slotKey = $key;

                    if ($frame !== null && $slot['scene'] !== null) {
                        $frame['announce'] = true;
                    } else {
                        $this->log('rotation: promo loop');
                    }
                }

                if ($modes->mode() !== $before) {
                    $this->log('mode '.$before.' -> '.$modes->mode());
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
                if ($run === null || $run->mode !== ModeMachine::SCENE || $frame === null || $now - $run->lastFrameAt < 1) {
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
                $rendered = $this->sendSceneFrame($run, $renderer, $frame, $now, $renderFailingSince === null);
                $renderFailingSince = $rendered ? null : ($renderFailingSince ?? $attemptAt);

                if ($rendered && $frame['announce']) {
                    // One line per slot, with what its first frame cost (Blade + rsvg-convert).
                    $this->log(sprintf('rotation: %s, rendered in %.0f ms', $frame['label'], 1000 * (microtime(true) - $attemptAt)));
                    $frame['announce'] = false;
                }
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

            $texts = $this->active?->mode === ModeMachine::SCENE ? StreamTexts::forGames($sceneGames, $sceneMore) : StreamTexts::for(null);
            $published = $cover->image() === null ? $texts : [...$texts, 'image' => $cover->image()];
            // A new viewer count or cover is republished like a text change (at most once per text_change_seconds).
            $announced = [...$published, 'viewers' => $viewerCount];
            $this->cacheAnnouncement(['viewers' => $viewerCount, 'title' => $texts['title'], 'summary' => $texts['summary']], $now);

            // A playlist kept from before this start is fresh after a quick
            // restart (and rewritten when trimmed), but says nothing about
            // whether an encoder of this process works: only its segments count.
            if ($this->signer !== null && $public->hasSegmentOf($this->runIds) && $this->isFresh($public->path()) && $schedule->due($announced, time())) {
                $this->startedAt ??= $this->resumedStarts ?? time();
                // A SIGTERM during this publish aborts it.
                $this->publish($builder, $publisher, 'live', $published, $this->publishTimeout(), fn (): bool => $this->stopping, $viewerCount);
                $schedule->published($announced, time());
            }

            usleep(250_000);
        }
    }

    /**
     * Stop the encoders and close the viewer socket; nothing is published (a
     * deploy restarts the daemon, and the next start continues the session;
     * `twentyone:stream:end` is the permanent stop). The public playlist and
     * its segments stay for the next start. Best effort: runs on every exit,
     * exceptions included.
     */
    private function shutdown(PublicPlaylist $public): void
    {
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
        $this->viewers?->close();
        $this->viewers = null;
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
     * The rotation slot's view and data, and the old single/gallery scene as
     * the fallback while games are on show. The previous frame stays when the
     * data cannot be built (a database that fails mid-read).
     *
     * @param  array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}  $slot
     * @param  list<ChessGame>  $games
     * @param  array<string, mixed>  $stats
     * @param  array{view: view-string, data: array<string, mixed>, fallback: array<string, mixed>|null, label: string, announce: bool}|null  $previous
     * @param  list<array<string, mixed>>  $tournaments  TournamentSlides::frames() of this poll
     * @param  int|null  $viewers  the live viewer count every scene gets as `viewers` (null: not counted)
     * @return array{view: view-string, data: array<string, mixed>, fallback: array<string, mixed>|null, label: string, announce: bool}|null
     */
    private function frameFor(SceneSource $source, array $slot, array $games, int $more, int $nowMs, array $stats, ?array $previous, array $tournaments = [], ?int $viewers = null): ?array
    {
        $scene = (string) $slot['scene'];
        $tournament = collect($tournaments)->firstWhere('id', $slot['tournamentId']);

        try {
            return [
                'view' => RotationPlanner::VIEWS[$scene],
                'data' => [...$source->rotation($scene, $slot['gameId'], $games, $more, $nowMs, $stats, $tournament, $tournaments), 'viewers' => $viewers],
                'fallback' => $games === [] ? null : [...$source->gallery($games, $more, $nowMs), 'viewers' => $viewers],
                'label' => $scene.' '.$slot['kind'].($slot['gameId'] !== null ? ' game '.$slot['gameId'] : '').($slot['tournamentId'] !== null ? ' '.$slot['tournamentId'] : ''),
                'announce' => $previous['announce'] ?? false,
            ];
        } catch (Throwable $e) {
            $this->log('scene data for '.$scene.' not built, keeping the last frame: '.$this->describe($e));

            return $previous;
        }
    }

    /**
     * Make the next slide the 30311 picture (StreamCover). A slide that cannot
     * be built or rendered keeps the current picture; tried again a minute later.
     *
     * @param  list<array<string, mixed>>  $tournaments  TournamentSlides::frames() of this poll
     * @param  list<ChessGame>  $games
     * @param  array<string, mixed>  $stats
     */
    private function advanceCover(StreamCover $cover, SceneSource $source, array $tournaments, array $games, int $more, array $stats, float $now): void
    {
        try {
            $label = $cover->advance($now, array_column($tournaments, 'id'), fn (string $scene, ?int $tournamentId): array => [
                ...$source->rotation($scene, null, $games, $more, (int) ($now * 1000), $stats, collect($tournaments)->firstWhere('id', $tournamentId), $tournaments),
                'viewers' => null,
            ]);
            $this->log('cover: '.$label.' '.$cover->image());
        } catch (Throwable $e) {
            $this->log('cover not rendered, keeping '.($cover->image() ?? 'the configured image').': '.$this->describe($e));
        }
    }

    /**
     * What the stream announces now, for the website's LIVE badge:
     * `twentyone.stream.announced` = {viewers, title, summary}, kept 60 s and
     * put again on every change and at least every 20 s, so it lasts while
     * the daemon runs and is gone a minute after it stopped. A failing cache
     * store never stops the stream (logged once per series).
     *
     * @param  array{viewers: int|null, title: string, summary: string}  $state
     */
    private function cacheAnnouncement(array $state, float $now): void
    {
        if ($state === $this->cachedAnnouncement && $now - $this->announcementCachedAt < self::ANNOUNCE_REFRESH_SECONDS) {
            return;
        }

        try {
            Cache::put(self::ANNOUNCE_CACHE_KEY, $state, self::ANNOUNCE_TTL_SECONDS);
            $this->cachedAnnouncement = $state;
            $this->announcementCachedAt = $now;
            $this->announceCacheFailing = false;
        } catch (Throwable $e) {
            if (! $this->announceCacheFailing) {
                $this->log('announced state not cached: '.$this->describe($e));
                $this->announceCacheFailing = true;
            }
        }
    }

    /**
     * The counts for the teasers (StreamStats, cached); the last ones when
     * the database fails, so a teaser never shows invented numbers.
     *
     * @param  array<string, mixed>  $last
     * @return array<string, mixed>
     */
    private function readStats(StreamStats $counts, array $last, bool $databaseUp): array
    {
        if (! $databaseUp) {
            return $last;
        }

        try {
            return $counts->all();
        } catch (Throwable $e) {
            return $last;
        }
    }

    /**
     * The upcoming tournaments' snapshots (TournamentSlides, cached); the
     * last ones while the database fails. Their close times still apply:
     * tournamentFrames() drops a tournament once its sign-up closed.
     *
     * @param  list<array<string, mixed>>  $last
     * @return list<array<string, mixed>>
     */
    private function readTournaments(TournamentSlides $slides, array $last, bool $databaseUp): array
    {
        if (! $databaseUp) {
            return $last;
        }

        try {
            $snapshots = $slides->snapshots();
            $this->tournamentsFailing = false;

            return $snapshots;
        } catch (Throwable $e) {
            if (! $this->tournamentsFailing) {
                $this->log('upcoming tournaments not read, keeping the last '.count($last).': '.$this->describe($e));
                $this->tournamentsFailing = true;
            }

            return $last;
        }
    }

    /**
     * The tournament slides' data at `$nowMs`; none when it cannot be built,
     * so the rotation goes on without them.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, mixed>>
     */
    private function tournamentFrames(TournamentSlides $slides, array $snapshots, int $nowMs): array
    {
        try {
            $frames = $slides->frames($snapshots, $nowMs);
            $this->tournamentFramesFailing = false;

            return $frames;
        } catch (Throwable $e) {
            if (! $this->tournamentFramesFailing) {
                $this->log('tournament slides not built, the rotation goes on without them: '.$this->describe($e));
                $this->tournamentFramesFailing = true;
            }

            return [];
        }
    }

    /**
     * One pass of the prepared promo in seconds (ffprobe), for the loop's
     * slot in the rotation; the configured fallback when it cannot be read.
     */
    private function promoSeconds(string $prepared): float
    {
        $fallback = (float) config('twentyone.stream.rotation.loop_fallback_seconds', 60);

        try {
            $result = Process::timeout(10)->env(ChildEnvironment::withoutSecrets())
                ->run([(string) config('twentyone.stream.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $prepared]);
            $seconds = (float) trim($result->output());
        } catch (Throwable $e) {
            $seconds = 0.0;
        }

        if ($seconds < 1) {
            $this->log('promo length not readable, the loop slot lasts '.$fallback.' s');

            return $fallback;
        }

        return $seconds;
    }

    /**
     * Render and send one frame: the rotation scene, else the old
     * single/gallery scene while games are on show, else the last good frame
     * again, so the picture holds instead of the encoder starving; only the
     * first failure of a series is logged.
     *
     * @param  array{view: view-string, data: array<string, mixed>, fallback: array<string, mixed>|null, label: string, announce: bool}  $frame
     * @return bool whether this frame rendered
     */
    private function sendSceneFrame(EncoderRun $run, SceneRenderer $renderer, array $frame, float $now, bool $logFailure): bool
    {
        try {
            try {
                $png = $renderer->png($frame['data'], $frame['view']);
                $this->rotationFailing = false;
            } catch (Throwable $e) {
                if ($frame['fallback'] === null) {
                    throw $e;
                }

                if (! $this->rotationFailing) {
                    $this->log('rotation scene '.$frame['label'].' failed, showing the game scene: '.$this->describe($e));
                    $this->rotationFailing = true;
                }

                $png = $renderer->png($frame['fallback']);
            }

            $run->sendFrame($png, $now);

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
        $instrumentals = $this->musicFiles((string) config('twentyone.stream.music.instrumental_dir'));
        // With instrumentals every vocal track is followed by one: a pass lasts twice as long.
        $perVocal = max(1, (int) config('twentyone.stream.music.instrumentals_per_vocal', 3));
        $seconds = max(1, count($files) * self::ASSUMED_TRACK_SECONDS * ($instrumentals === [] ? 1 : 1 + $perVocal));
        $passes = (int) ceil(3600 * (int) config('twentyone.stream.music.list_hours', 12) / $seconds);
        $path = rtrim((string) config('twentyone.stream.scene.work_dir'), '/').'/music.ffconcat';
        File::ensureDirectoryExists(dirname($path));
        PlaylistWriter::writeAtomically($path, MusicPlaylist::ffconcat(MusicPlaylist::interleave(MusicPlaylist::order($files, max(1, $passes)), $instrumentals, perVocal: $perVocal)));

        return $path;
    }

    /**
     * The music files of `$dir` (default: the vocal folder), without names
     * that carry control characters (they could not be written into the
     * ffconcat list safely).
     *
     * @return list<string>
     */
    private function musicFiles(?string $dir = null): array
    {
        $dir ??= (string) config('twentyone.stream.music.dir');

        if (trim($dir) === '') {
            return [];
        }

        $files = File::glob(rtrim($dir, '/').'/*__v*.m4a');

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
     * @param  array{title: string, summary: string, image?: string}  $texts  `image` replaces the configured picture
     * @param  (Closure(): bool)|null  $abort
     * @param  int|null  $viewers  `current_participants`, left out when null
     */
    private function publish(EventBuilder $builder, RelayPublisher $publisher, string $status, array $texts, float $timeoutSeconds, ?Closure $abort = null, ?int $viewers = null): void
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
            null,
            $viewers,
        )->setCreatedAt($createdAt);
        $event = $this->signer->sign($unsigned);
        $this->lastCreatedAt = $createdAt;

        $results = $publisher->publish($event, $this->relays, $timeoutSeconds, $abort);
        $summary = collect($results)->map(fn ($result): string => $result->relay.' '.($result->accepted ? 'ok' : 'failed: '.$result->message));
        $accepted = collect($results)->where('accepted', true)->count();

        // Only an accepted `live` keeps the session resumable.
        if ($status === 'live' && $accepted > 0 && $this->session !== null) {
            try {
                $this->session->recordLive($this->startedAt, $createdAt);
            } catch (Throwable $e) {
                $this->log('session not recorded: '.$this->describe($e));
            }
        }

        $this->log(sprintf(
            'published kind 30311 status=%s starts=%d id=%s created_at=%d to %d/%d relays (%s)',
            $status,
            $this->startedAt,
            $event['id'],
            $event['created_at'],
            $accepted,
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
