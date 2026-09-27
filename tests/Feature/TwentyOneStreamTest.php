<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\TwentyOne\Stream\ViewerCounter;
use App\Support\TwentyOne\Stream\ViewerSocket;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;

pest()->group('nostr');

beforeEach(function () {
    $this->key = new TestSigner;
    $this->nsec = (new Key)->convertPrivateKeyToBech32($this->key->secret);
    $this->dir = storage_path('framework/testing/stream-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir);

    config([
        'twentyone.nostr.nsec' => $this->nsec,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->key->pubkey),
        'twentyone.stream.prepared' => $this->dir.'/promo-stream.mp4',
        'twentyone.stream.hls_dir' => $this->dir.'/hls',
        // Any executable passes the start-up check; the tests fake or replace it.
        'twentyone.stream.ffmpeg' => PHP_BINARY,
        'twentyone.stream.scene.rsvg_convert' => PHP_BINARY,
        'twentyone.stream.scene.work_dir' => $this->dir.'/work',
        'twentyone.stream.music.dir' => $this->dir.'/music',
        'twentyone.stream.music.instrumental_dir' => $this->dir.'/music/instrumental',
        // A socket directory of its own per test (parallel workers), short enough for sun_path (107 bytes);
        // no ACL, as there is no `forge` user here.
        'twentyone.stream.viewers.dir' => sys_get_temp_dir().'/tos-'.bin2hex(random_bytes(6)),
        'twentyone.stream.viewers.nginx_user' => '',
        'twentyone.stream.session_file' => $this->dir.'/session.json',
    ]);

    File::ensureDirectoryExists($this->dir.'/music');

    foreach (['a__v1', 'a__v2', 'b__v1', 'c__v1'] as $track) {
        File::put($this->dir."/music/{$track}.m4a", 'aac');
    }
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    $viewerDir = config('twentyone.stream.viewers.dir');
    is_link($viewerDir) ? unlink($viewerDir) : File::deleteDirectory($viewerDir);
    File::deleteDirectory($viewerDir.'-target');

    foreach (['TWENTYONE_NOSTR_NSEC', 'TWENTYONE_TEST_API_TOKEN', 'FAKE_ENCODER_CAPTURE', 'FAKE_ENCODER_SEGMENTS', 'FAKE_ENCODER_DELAY', 'FAKE_ENCODER_EXIT_AFTER', 'FAKE_ENCODER_LOOP_EXIT_AFTER', 'FAKE_ENCODER_SCENE_DELAY'] as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

/**
 * Put a variable into the environment children inherit. Symfony Process
 * passes on only names that are also in $_SERVER, so putenv() alone is not
 * enough.
 */
function setChildEnv(string $name, string $value): void
{
    putenv($name.'='.$value);
    $_SERVER[$name] = $value;
}

/**
 * An ffmpeg stand-in: a shell script with the given body.
 */
function fakeFfmpeg(string $dir, string $body): string
{
    File::put($dir.'/ffmpeg', "#!/bin/sh\n".$body."\n");
    chmod($dir.'/ffmpeg', 0755);
    config(['twentyone.stream.ffmpeg' => $dir.'/ffmpeg']);

    return $dir.'/ffmpeg';
}

/**
 * An ffmpeg stand-in that writes run-prefixed segments like the HLS muxer
 * (tests/Support/fake-encoder.php).
 */
function fakeEncoder(string $dir): string
{
    return fakeFfmpeg($dir, 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/fake-encoder.php')).' "$@"');
}

/**
 * The files a public playlist references (segments and init files), as
 * paths under hls_dir.
 *
 * @return list<string>
 */
function referencedFiles(string $playlist): array
{
    preg_match_all('/^(?:#EXT-X-MAP:URI="([^"]+)"|([^#\s]\S*))$/m', $playlist, $matches);

    return array_values(array_unique(array_filter([...$matches[1], ...$matches[2]])));
}

test('the live event puts one m3u8 streaming tag and a four-element host p tag in order', function () {
    $stream = config('twentyone.stream.event');
    $url = 'https://esports.einundzwanzig.space/live/stream.m3u8';
    $event = SignedEvent::fromInput(TwentyOneSigner::fromNsec($this->nsec)->sign(
        (new EventBuilder)->liveActivity($stream, $url, $this->key->pubkey, 'ended', 1790000000, 1790003600),
    ));

    expect($event->kind)->toBe(30311)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tags)->toBe([
            ['d', 'twentyone-247'],
            ['title', $stream['title']],
            ['summary', $stream['summary']],
            ['image', $stream['image']],
            ['status', 'ended'],
            ['starts', '1790000000'],
            ['ends', '1790003600'],
            ['streaming', $url],
            ['t', 'bitcoin'], ['t', 'esports'], ['t', 'nostr'], ['t', 'einundzwanzig'], ['t', 'gaming'],
            ['p', $this->key->pubkey, '', 'host'],
        ]);

    expect(fn () => (new EventBuilder)->liveActivity($stream, $url.'?v=1', $this->key->pubkey, 'live', 1790000000))
        ->toThrow(InvalidArgumentException::class);
});

test('the stream refuses to start without the prepared file', function () {
    Process::fake();

    $this->artisan('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2])
        ->expectsOutputToContain('Prepared stream file not found')
        ->assertExitCode(1);

    Process::assertNothingRan();
});

test('the stream refuses an unplayable streaming URL before anything starts', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    config(['twentyone.stream.public_url' => 'https://esports.einundzwanzig.space/live/stream.m3u8?v=2']);
    Process::fake();

    $startedAt = microtime(true);
    $this->artisan('twentyone:stream', ['--relays' => 'ws://127.0.0.1:1', '--stop-after' => 2])
        ->expectsOutputToContain('TWENTYONE_STREAM_URL must be an http(s) URL ending in .m3u8')
        ->assertExitCode(1);

    expect(microtime(true) - $startedAt)->toBeLessThan(1.0);
    Process::assertNothingRan();
});

test('the supervisor survives an ffmpeg killed from outside and schedules a restart', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeFfmpeg($this->dir, 'kill -KILL $$');

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('ffmpeg exited by signal 9', 'restarting ffmpeg in 5 s');
});

test('ffmpeg does not inherit the nsec or other secrets from the environment', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    setChildEnv('TWENTYONE_NOSTR_NSEC', $this->nsec);
    setChildEnv('TWENTYONE_TEST_API_TOKEN', 'not-for-children');
    fakeFfmpeg($this->dir, 'env > "$(dirname "$0")/child-env.txt"; exec sleep 5');

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1]);
    $environment = (string) file_get_contents($this->dir.'/child-env.txt');

    // Booleans only: a failure message must not print the environment.
    expect(str_contains($environment, 'PATH='))->toBeTrue()
        ->and(str_contains($environment, $this->nsec))->toBeFalse()
        ->and(preg_match('/^(TWENTYONE_NOSTR_NSEC|TWENTYONE_TEST_API_TOKEN|APP_KEY)=/m', $environment))->toBe(0);
});

test('on stop the stream publishes no ended event, stops ffmpeg and keeps the playlist', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $hlsDir = config('twentyone.stream.hls_dir');
    // Three relays that accept the connection and never answer.
    $silent = array_map(fn () => stream_socket_server('tcp://127.0.0.1:0'), range(1, 3));
    $relays = implode(',', array_map(fn ($server): string => 'ws://'.stream_socket_get_name($server, false), $silent));
    config(['twentyone.nostr.publish_timeout_seconds' => 0.3, 'twentyone.stream.shutdown_publish_seconds' => 0.3]);
    // The stand-in writes a fresh playlist and a segment, then runs until SIGTERM.
    fakeEncoder($this->dir);

    $startedAt = microtime(true);
    $exitCode = Artisan::call('twentyone:stream', ['--relays' => $relays, '--stop-after' => 1]);
    $elapsed = microtime(true) - $startedAt;
    $output = Artisan::output();

    preg_match('/status=live starts=\d+ id=\w+ created_at=(\d+) to 0\/3 relays/', $output, $live);

    expect($exitCode)->toBe(0)
        // live (0.3 s budget, all relays in parallel, not 3 × per relay) + stop-after (1 s).
        // Measured with an `ended` on stop as well: 1.85 s idle, up to 2.14 s under 2× CPU
        // oversubscription; without it the margin under 3.0 s only grew.
        ->and($elapsed)->toBeLessThan(3.0)
        ->and($live)->not->toBe([])
        // A deploy restarts the daemon: the live event stays, nothing says ended.
        ->and($output)->not->toContain('status=ended')
        ->and($output)->toContain('ffmpeg stopped')
        // No relay accepted the live event, so there is no session to continue.
        ->and(File::exists($this->dir.'/session.json'))->toBeFalse()
        ->and(File::exists($hlsDir.'/stream.m3u8'))->toBeTrue()
        ->and(array_filter(referencedFiles((string) file_get_contents($hlsDir.'/stream.m3u8')), fn (string $uri): bool => ! is_file($hlsDir.'/'.$uri)))->toBe([])
        ->and($output)->not->toContain($this->nsec)
        ->and($output)->not->toContain($this->key->secret)
        ->and(substr_count($output, 'ffmpeg started'))->toBe(1);
});

test('a daemon restart publishes new names and never lowers MEDIA-SEQUENCE', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    $published = [];

    foreach ([1, 2] as $run) {
        setChildEnv('FAKE_ENCODER_CAPTURE', $this->dir."/published-{$run}.m3u8");
        Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);
        $published[$run] = (string) file_get_contents($this->dir."/published-{$run}.m3u8");
    }

    preg_match_all('/^(\S+-seg-\d+\.m4s)$/m', $published[1], $first);
    preg_match_all('/^(\S+-seg-\d+\.m4s)$/m', $published[2], $second);
    preg_match('/MEDIA-SEQUENCE:(\d+)/', $published[1], $firstSequence);
    preg_match('/MEDIA-SEQUENCE:(\d+)/', $published[2], $secondSequence);

    expect($first[1])->toHaveCount(3)
        // Run 2 appends to the window run 1 left: its 3 segments, then 3 new names.
        ->and(array_slice($second[1], 0, 3))->toBe($first[1])
        ->and(array_slice($second[1], 3))->toHaveCount(3)
        ->and(array_intersect($first[1], array_slice($second[1], 3)))->toBe([])
        // Run 1 starts at the clock floor (no state yet); run 2 continues it.
        ->and((int) $firstSequence[1])->toBeGreaterThanOrEqual(intdiv(time() - 10, 2))
        ->and((int) $secondSequence[1])->toBe((int) $firstSequence[1])
        ->and(substr_count($published[2], '#EXT-X-DISCONTINUITY'."\n"))->toBe(1)
        ->and(substr_count($published[2], '#EXT-X-MAP:URI="loop/'))->toBe(2);
});

/**
 * A SceneSource whose sceneGames() answers from a script: the one game on
 * show, none (null), or a Throwable to throw, one entry per poll (the last one repeats).
 *
 * @param  list<ChessGame|Throwable|null>  $answers
 */
function scriptedSource(array $answers): void
{
    $source = Mockery::mock(SceneSource::class, [app(ChessGameService::class), app(GameRegistry::class), app(StreamImages::class)])->makePartial();
    $source->shouldReceive('sceneGames')->andReturnUsing(function () use (&$answers) {
        $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return ['games' => $answer === null ? [] : [$answer->fresh(['white', 'black'])], 'more' => 0];
    });
    app()->instance(SceneSource::class, $source);
}

/**
 * A render "binary" that answers with a PNG signature; with `$failRotation`
 * it fails on the rotation scenes (their defs carry "mark-dark") and
 * renders the old game scene.
 */
function fakeRenderer(string $dir, bool $failRotation = false): void
{
    $check = $failRotation ? 'if printf %s "$svg" | grep -q mark-dark; then echo rotation broken >&2; exit 1; fi'."\n" : '';
    File::put($dir.'/rsvg-convert', "#!/bin/sh\nsvg=$(cat)\n{$check}printf '\\211PNG'\n");
    chmod($dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $dir.'/rsvg-convert']);
}

/**
 * Short rotation slots, so a few seconds show a whole round.
 */
function shortRotation(): void
{
    config(['twentyone.stream.rotation' => [
        'match_seconds' => 1, 'blitz_match_seconds' => 1, 'gallery_seconds' => 1, 'teaser_seconds' => 1,
        'teasers_per_round' => 3, 'loop_every_rounds' => 3, 'loop_fallback_seconds' => 1,
    ]]);
}

/**
 * A render "binary" that always fails.
 */
function failingRenderer(string $dir): void
{
    File::put($dir.'/rsvg-convert', "#!/bin/sh\necho broken >&2\nexit 1\n");
    chmod($dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $dir.'/rsvg-convert']);
}

test('a failing database poll counts as no live game instead of stopping the stream', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    scriptedSource([new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')]);

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(substr_count($output, 'database poll failed, treating as no live game: PDOException'))->toBe(1)
        ->and($output)->toContain('ffmpeg started mode=loop', 'stopped');
});

test('an exception in the supervisor stops cleanly and keeps the playlist of the last run', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $hlsDir = config('twentyone.stream.hls_dir');
    fakeEncoder($this->dir);
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);
    $kept = (string) file_get_contents($hlsDir.'/stream.m3u8');
    Process::fake(fn () => throw new RuntimeException('cannot start ffmpeg'));

    expect(fn () => Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]))->toThrow(RuntimeException::class, 'cannot start ffmpeg')
        ->and((string) file_get_contents($hlsDir.'/stream.m3u8'))->toBe($kept)
        ->and(array_filter(referencedFiles($kept), fn (string $uri): bool => ! is_file($hlsDir.'/'.$uri)))->toBe([]);
});

test('a restart serves the kept playlist, with all its files, until the new encoder has a segment', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $hlsDir = config('twentyone.stream.hls_dir');
    fakeEncoder($this->dir);
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);
    $kept = (string) file_get_contents($hlsDir.'/stream.m3u8');

    // The next encoder needs longer for its first segment than this run lasts.
    setChildEnv('FAKE_ENCODER_DELAY', '5');
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1.5]);

    expect(referencedFiles($kept))->toHaveCount(4)
        ->and((string) file_get_contents($hlsDir.'/stream.m3u8'))->toBe($kept)
        ->and(array_filter(referencedFiles($kept), fn (string $uri): bool => ! is_file($hlsDir.'/'.$uri)))->toBe([]);
});

test('a kept playlist does not count as live before this run has written it', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    config(['twentyone.nostr.publish_timeout_seconds' => 1, 'twentyone.stream.shutdown_publish_seconds' => 1]);
    fakeEncoder($this->dir);
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);

    // Restarted within FRESH_SECONDS, with an encoder that never gets a segment out.
    setChildEnv('FAKE_ENCODER_DELAY', '5');
    Artisan::call('twentyone:stream', ['--relays' => 'ws://'.stream_socket_get_name($silent, false), '--stop-after' => 1.5]);

    expect(Artisan::output())->not->toContain('status=live');
});

test('a rewritten kept window is not live either: only a segment of this process counts', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $hlsDir = config('twentyone.stream.hls_dir');
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    config(['twentyone.nostr.publish_timeout_seconds' => 1, 'twentyone.stream.shutdown_publish_seconds' => 1]);
    fakeEncoder($this->dir);
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);

    // The kept playlist is gone (the state still has its window), so the next
    // update rewrites it; the new encoder dies without a segment.
    File::delete($hlsDir.'/stream.m3u8');
    setChildEnv('FAKE_ENCODER_SEGMENTS', '0');
    setChildEnv('FAKE_ENCODER_EXIT_AFTER', '0.1');
    Artisan::call('twentyone:stream', ['--relays' => 'ws://'.stream_socket_get_name($silent, false), '--stop-after' => 2]);

    expect(File::exists($hlsDir.'/stream.m3u8'))->toBeTrue()
        ->and(Artisan::output())->not->toContain('status=live');
});

test('--clear starts from an empty window, a lost state drops the kept playlist', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $hlsDir = config('twentyone.stream.hls_dir');
    fakeEncoder($this->dir);
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);
    setChildEnv('FAKE_ENCODER_DELAY', '5');

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1, '--clear' => true]);

    expect(Artisan::output())->toContain('--clear: removed the public playlist and all segments')
        ->and(File::exists($hlsDir.'/stream.m3u8'))->toBeFalse()
        ->and(File::glob($hlsDir.'/loop/*.m4s'))->toBe([])
        ->and(File::exists($hlsDir.'/stream.m3u8.state.json'))->toBeTrue();

    // A playlist without its state: nothing says which files it needs.
    File::put($hlsDir.'/stream.m3u8', "#EXTM3U\n");
    File::delete($hlsDir.'/stream.m3u8.state.json');
    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1]);

    expect(Artisan::output())->toContain('removed a public playlist that has no readable state')
        ->and(File::exists($hlsDir.'/stream.m3u8'))->toBeFalse();
});

test('a scene that cannot be rendered gives way to the loop', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    failingRenderer($this->dir);
    config(['twentyone.stream.scene.render_failure_seconds' => 2]);
    ChessGame::factory()->create();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 5]);
    $output = Artisan::output();

    expect($output)->toMatch('/scene render failing for \\d+ s, back to the loop/')
        ->and($output)->toContain('ffmpeg started mode=scene', 'ffmpeg started mode=loop');
});

test('an active daily game alone brings the scene, not the loop', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    ChessGame::factory()->daily()->create(['ply' => 68]);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3]);

    expect(Artisan::output())->toContain('ffmpeg started mode=scene')
        ->and(Artisan::output())->not->toContain('ffmpeg started mode=loop');
});

test('with two games the rotation shows match, gallery and teasers', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    $blitz = ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 5.5]);
    $output = Artisan::output();

    expect($output)->toContain('rotation: a1 match game '.$blitz->id.', rendered in', 'rotation: a2 gallery, rendered in', 'rotation: a3 teaser, rendered in', 'rotation: a4 teaser')
        ->and($output)->not->toContain('rotation: promo loop')
        ->and($output)->not->toContain('ffmpeg started mode=loop');
});

test('without games the stream starts on the promo loop and goes on with the teasers', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);
    $output = Artisan::output();

    expect($output)->toContain('promo length not readable, the loop slot lasts 1 s', 'rotation: promo loop', 'ffmpeg started mode=loop', 'rotation: a3 teaser', 'ffmpeg started mode=scene')
        ->and(strpos($output, 'rotation: promo loop'))->toBeLessThan(strpos($output, 'rotation: a3 teaser'));
});

test('a rotation scene that fails to render gives way to the game scene, not to the loop', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir, failRotation: true);
    config(['twentyone.stream.scene.render_failure_seconds' => 2]);
    $game = ChessGame::factory()->create();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);
    $output = Artisan::output();

    expect(substr_count($output, 'rotation scene a1 match game '.$game->id.' failed, showing the game scene'))->toBe(1)
        ->and($output)->not->toContain('back to the loop')
        ->and($output)->not->toContain('ffmpeg started mode=loop');
});

test('a hanging renderer is cut off after its timeout and the loop takes over by wall-clock time', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    File::put($this->dir.'/rsvg-convert', "#!/bin/sh\nexec sleep 30\n");
    chmod($this->dir.'/rsvg-convert', 0755);
    config([
        'twentyone.stream.scene.rsvg_convert' => $this->dir.'/rsvg-convert',
        'twentyone.stream.scene.render_timeout_seconds' => 1,
        'twentyone.stream.scene.render_failure_seconds' => 3,
    ]);
    ChessGame::factory()->create();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 7]);

    expect(Artisan::output())->toMatch('/scene render failing for [3-5] s, back to the loop/');
});

test('an encoder that stops writing segments is restarted by the watchdog', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    setChildEnv('FAKE_ENCODER_SEGMENTS', '0');
    config(['twentyone.stream.watchdog_seconds' => 2]);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);

    expect(Artisan::output())->toMatch('/ffmpeg mode=loop wrote no segment for \d+ s, restarting it/');
});

test('a new encoder takes over even when the old one died during the switch', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    // Loop dies after 1.5 s; the scene needs 2.5 s for its first segment.
    setChildEnv('FAKE_ENCODER_LOOP_EXIT_AFTER', '1.5');
    setChildEnv('FAKE_ENCODER_SCENE_DELAY', '2.5');
    failingRenderer($this->dir);
    $game = ChessGame::factory()->create();
    scriptedSource([null, $game]);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 5]);
    $output = Artisan::output();

    expect($output)->toContain('ffmpeg exited', 'switched to scene');
});

test('player names reach the 30311 without control or bidi characters', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    failingRenderer($this->dir);
    config(['twentyone.stream.shutdown_publish_seconds' => 1]);
    ChessGame::factory()->create([
        'white_id' => User::factory()->create(['name' => "Mallory\u{202E}gnp.exe\nline two\u{200B}"]),
    ]);
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $this->dir.'/event.json'], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);

    Artisan::call('twentyone:stream', ['--relays' => 'ws://127.0.0.1:'.$port, '--stop-after' => 3]);
    proc_terminate($relay);
    proc_close($relay);
    $event = json_decode((string) file_get_contents($this->dir.'/event.json'), true)[1];
    $title = collect($event['tags'])->firstWhere(0, 'title')[1];

    expect($title)->toStartWith('Live now: Mallory gnp.exe line two vs ')
        ->and(preg_match('/\p{C}/u', $title.collect($event['tags'])->firstWhere(0, 'summary')[1]))->toBe(0);
});

test('with two live games the supervisor announces the gallery', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    failingRenderer($this->dir);
    config(['twentyone.stream.shutdown_publish_seconds' => 1]);
    ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $this->dir.'/event.json'], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);

    Artisan::call('twentyone:stream', ['--relays' => 'ws://127.0.0.1:'.$port, '--stop-after' => 3]);
    proc_terminate($relay);
    proc_close($relay);
    $event = json_decode((string) file_get_contents($this->dir.'/event.json'), true)[1];

    expect(collect($event['tags'])->firstWhere(0, 'title')[1])->toBe('Live now: 2 chess games');
});

test('the work dir may not lie inside the served HLS directory', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    config(['twentyone.stream.scene.work_dir' => config('twentyone.stream.hls_dir').'/work']);
    Process::fake();

    $this->artisan('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1])
        ->expectsOutputToContain('must not contain each other')
        ->assertExitCode(1);
    Process::assertNothingRan();
});

test('the 30311 names the game the scene shows, and the loop texts otherwise', function () {
    $game = ChessGame::factory()->create([
        'white_id' => User::factory()->create(['name' => 'Alice']),
        'black_id' => User::factory()->create(['name' => str_repeat('B', 50)]),
    ]);

    expect(StreamTexts::for($game))->toBe([
        'title' => 'Live now: Alice vs '.str_repeat('B', 39).'… · Chess Blitz',
        'summary' => 'Alice vs '.str_repeat('B', 39).'…: live blitz chess on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Play the next game at esports.einundzwanzig.space. Login via Nostr.',
    ])->and(StreamTexts::for(null))->toBe([
        'title' => config('twentyone.stream.event.title'),
        'summary' => config('twentyone.stream.event.summary'),
    ]);
});

test('an open tournament brings its slides into the rotation, and their countdown ticks every second', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    // Each render keeps the countdown the slide shows (its one hh:mm:ss text).
    File::put($this->dir.'/rsvg-convert', "#!/bin/sh\nsvg=$(cat)\nprintf %s \"\$svg\" | grep -oE '[0-9]{2}:[0-9]{2}:[0-9]{2}' >> ".$this->dir."/countdowns\nprintf '\\211PNG'\n");
    chmod($this->dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $this->dir.'/rsvg-convert']);
    shortRotation();
    config(['twentyone.stream.rotation.tournament_seconds' => 2.5]);
    $tournament = openTournament();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 7.5]);
    $output = Artisan::output();
    $countdowns = array_values(array_unique(file($this->dir.'/countdowns', FILE_IGNORE_NEW_LINES) ?: []));

    expect($output)->toContain('rotation: promo loop', 'rotation: ta1 tournament '.$tournament->id.', rendered in', 'rotation: ta2 tournament '.$tournament->id.', rendered in', 'rotation: a3 teaser')
        ->and(strpos($output, 'rotation: ta1 tournament'))->toBeLessThan(strpos($output, 'rotation: ta2 tournament'))
        ->and(strpos($output, 'rotation: ta2 tournament'))->toBeLessThan(strpos($output, 'rotation: a3 teaser'))
        ->and($output)->not->toContain('not built')
        // A render per second while the slide is on: the countdown moves.
        ->and(count($countdowns))->toBeGreaterThanOrEqual(2)
        ->and($countdowns[0])->toMatch('/^23:59:\d\d$/');
});

test('upcoming tournaments that cannot be read leave the rotation to the teasers, logged once', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    $slides = Mockery::mock(TournamentSlides::class, [app(GameRegistry::class), app(StreamImages::class)])->makePartial();
    $slides->shouldReceive('snapshots')->andThrow(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    app()->instance(TournamentSlides::class, $slides);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);
    $output = Artisan::output();

    expect(substr_count($output, 'upcoming tournaments not read, keeping the last 0: PDOException'))->toBe(1)
        ->and($output)->toContain('rotation: promo loop', 'rotation: a3 teaser', 'rotation: a4 teaser')
        ->and($output)->not->toContain(' tournament ');
});

/**
 * Record the `viewers` every stream view is rendered with ('missing' when a
 * view gets none), as [view, viewers] pairs.
 *
 * @return ArrayObject<int, array{0: string, 1: int|string|null}>
 */
function captureViewers(): ArrayObject
{
    $seen = new ArrayObject;

    View::composer([...array_values(RotationPlanner::VIEWS), 'stream.scene'], function ($view) use ($seen): void {
        $data = $view->getData();
        $seen[] = [$view->name(), array_key_exists('viewers', $data) ? $data['viewers'] : 'missing'];
    });

    return $seen;
}

/**
 * A child process that plays nginx: it waits for the socket and then sends
 * `$datagrams` every 100 ms until `$seconds` have passed.
 *
 * @param  list<string>  $datagrams
 * @return resource
 */
function fakeNginx(string $socket, array $datagrams, float $seconds)
{
    $script = '$until = microtime(true) + '.$seconds.'; $lines = json_decode($argv[2], true);'
        .' while (microtime(true) < $until) { $c = @stream_socket_client("udg://".$argv[1]);'
        .' if ($c !== false) { foreach ($lines as $line) { @fwrite($c, $line); } fclose($c); } usleep(100000); }';

    return proc_open([PHP_BINARY, '-r', $script, $socket, json_encode($datagrams)], [], $pipes);
}

test('viewers counted from the socket reach every scene, the game scene fallback and the 30311', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    // The rotation scenes fail, so the old game scene renders as well.
    fakeRenderer($this->dir, failRotation: true);
    // The first live goes out after the counter has read the datagrams.
    setChildEnv('FAKE_ENCODER_DELAY', '1.5');
    config(['twentyone.stream.shutdown_publish_seconds' => 1]);
    ChessGame::factory()->create();
    $seen = captureViewers();
    $socket = config('twentyone.stream.viewers.dir').'/viewers.sock';
    $firefox = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $nginx = fakeNginx($socket, [
        "<190>Sep 27 12:00:00 hls: 203.0.113.1|{$firefox}|200",
        "<190>Sep 27 12:00:00 hls: 203.0.113.1|{$firefox}|304",
        '<190>Sep 27 12:00:00 hls: 203.0.113.1|VLC/3.0.20 LibVLC/3.0.20|206',
        "<190>Sep 27 12:00:00 hls: 2001:db8::7|{$firefox}|200",
        '<190>Sep 27 12:00:00 hls: 203.0.113.2|curl/8.10.1|200',
        "<190>Sep 27 12:00:00 hls: 203.0.113.3|{$firefox}|404",
        'garbage',
    ], 4);
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $this->dir.'/event.json'], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);

    Artisan::call('twentyone:stream', ['--relays' => 'ws://127.0.0.1:'.$port, '--stop-after' => 3]);
    $output = Artisan::output();
    proc_terminate($relay);
    proc_close($relay);
    proc_terminate($nginx);
    proc_close($nginx);
    $event = json_decode((string) file_get_contents($this->dir.'/event.json'), true)[1];
    $counted = collect($seen->getArrayCopy())->filter(fn (array $render): bool => $render[1] === 3);

    expect($output)->toContain('viewer count on '.$socket)
        ->and($output)->not->toContain('203.0.113', '2001:db8', 'Firefox')
        ->and(collect($seen->getArrayCopy())->where(1, 'missing')->all())->toBe([])
        ->and($counted->pluck(0)->unique()->sort()->values()->all())->toBe(['stream.rotation.a1-match', 'stream.scene'])
        ->and(collect($event['tags'])->where(0, 'current_participants')->values()->all())->toBe([['current_participants', '3']])
        // Created private: no access for other users.
        ->and(fileperms(dirname($socket)) & 0777)->toBe(0700)
        // The daemon removes its socket on the way out.
        ->and(file_exists($socket))->toBeFalse();
});

test('a socket that cannot be bound leaves the count off and the stream running', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    config(['twentyone.stream.shutdown_publish_seconds' => 1]);
    // One byte over sun_path: PHP would bind a truncated path instead of failing.
    $dir = str_pad(sys_get_temp_dir().'/tos-'.bin2hex(random_bytes(4)).'-', ViewerSocket::MAX_PATH_BYTES + 1 - strlen('/viewers.sock'), 'x');
    $socket = $dir.'/viewers.sock';
    config(['twentyone.stream.viewers.dir' => $dir]);
    $seen = captureViewers();
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $this->dir.'/event.json'], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);

    $exitCode = Artisan::call('twentyone:stream', ['--relays' => 'ws://127.0.0.1:'.$port, '--stop-after' => 4]);
    $output = Artisan::output();
    proc_terminate($relay);
    proc_close($relay);
    $event = json_decode((string) file_get_contents($this->dir.'/event.json'), true)[1];

    expect(strlen($socket))->toBe(ViewerSocket::MAX_PATH_BYTES + 1)
        ->and($exitCode)->toBe(0)
        ->and(substr_count($output, 'viewer count off: RuntimeException: socket path is empty or longer than 107 bytes (108)'))->toBe(1)
        ->and($output)->toContain('rotation: a3 teaser, rendered in', 'status=live')
        ->and($seen->count())->toBeGreaterThan(0)
        ->and(collect($seen->getArrayCopy())->pluck(1)->unique()->all())->toBe([null])
        ->and(collect($event['tags'])->where(0, 'current_participants')->all())->toBe([])
        ->and(file_exists(substr($socket, 0, ViewerSocket::MAX_PATH_BYTES)))->toBeFalse()
        ->and(file_exists($dir))->toBeFalse();
});

test('the viewer socket replaces a stale socket, never another file, and reads a limited batch per turn', function () {
    $dir = config('twentyone.stream.viewers.dir');
    $path = $dir.'/viewers.sock';
    mkdir($dir, 0700);
    File::put($path, 'not ours');

    expect(fn () => ViewerSocket::bind($dir))->toThrow(RuntimeException::class, 'not a socket, left in place')
        ->and(file_get_contents($path))->toBe('not ours');

    File::delete($path);
    // A socket a killed daemon left behind.
    fclose(stream_socket_server('udg://'.$path, $errorCode, $errorMessage, STREAM_SERVER_BIND));
    $socket = ViewerSocket::bind($dir);
    $client = stream_socket_client('udg://'.$path);

    foreach (range(1, 10) as $index) {
        fwrite($client, "<190>Sep 27 12:00:00 hls: 203.0.113.{$index}|Mozilla/5.0 Firefox/131.0|200");
    }

    $counter = new ViewerCounter(20);
    $batches = [$socket->drain($counter, 0, 4), $counter->count(0), $socket->drain($counter, 0, 100), $counter->count(0), $socket->drain($counter, 0, 100)];
    $mode = fileperms($path) & 0777;
    $socket->close();

    expect($batches)->toBe([4, 4, 6, 10, 0])
        ->and($mode)->toBe(0666)
        ->and(file_exists($path))->toBeFalse();
});

test('the viewer socket directory must be private: other bits, a symlink, a foreign owner or an injected ACL are refused, never repaired', function () {
    $base = config('twentyone.stream.viewers.dir');
    mkdir($base, 0700);

    // Open to other users: refused, and left as it is.
    mkdir($base.'/open', 0700);
    chmod($base.'/open', 0705);
    // A symlink to a directory that would pass every other check.
    mkdir($base.'/private', 0700);
    symlink($base.'/private', $base.'/link');
    // A second ACL entry smuggled in through the user name would open the directory to others.
    mkdir($base.'/injected', 0700);
    $me = posix_getpwuid(posix_geteuid())['name'];

    expect(fn () => ViewerSocket::bind($base.'/open'))->toThrow(RuntimeException::class, 'socket directory is open to other users (mode 705), refused')
        ->and(fileperms($base.'/open') & 0777)->toBe(0705)
        ->and(file_exists($base.'/open/viewers.sock'))->toBeFalse()
        ->and(fn () => ViewerSocket::bind($base.'/link'))->toThrow(RuntimeException::class, 'socket directory is a symlink, refused')
        ->and(file_exists($base.'/private/viewers.sock'))->toBeFalse()
        ->and(fn () => ViewerSocket::bind($base.'/injected', $me.':rwx,o::rwx,u:'.$me))->toThrow(RuntimeException::class, 'not a user name')
        ->and(fileperms($base.'/injected') & 0007)->toBe(0)
        ->and(fn () => ViewerSocket::bind($base.'/nosuchuser', 'nosuchuser_tos'))->toThrow(RuntimeException::class, 'setfacl for nosuchuser_tos failed');

    if (posix_geteuid() !== 0) {
        // /root belongs to root: not ours, whatever its mode.
        expect(fn () => ViewerSocket::bind('/root'))->toThrow(RuntimeException::class, 'socket directory is not owned by this user');
    }

    // The nginx user gets search access by ACL; "other" stays closed.
    $socket = ViewerSocket::bind($base.'/acl', $me);
    $acl = Process::run(['getfacl', '-cp', $base.'/acl'])->output();
    $socket->close();

    expect($acl)->toContain('user:'.$me.':--x')
        ->and($acl)->toContain('other::---');
});

test('a socket directory that is not private leaves the count off and the stream running', function (Closure $prepare, string $logged) {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    ChessGame::factory()->create();
    $prepare(config('twentyone.stream.viewers.dir'));
    $seen = captureViewers();

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 2]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(substr_count($output, 'viewer count off: RuntimeException: '.$logged))->toBe(1)
        ->and($output)->toContain('ffmpeg started mode=scene', 'rendered in')
        ->and($seen->count())->toBeGreaterThan(0)
        ->and(collect($seen->getArrayCopy())->pluck(1)->unique()->all())->toBe([null]);
})->with([
    'other bits set' => [function (string $dir): void {
        mkdir($dir, 0700);
        chmod($dir, 0701);
    }, 'socket directory is open to other users (mode 701)'],
    'setfacl for a user that does not exist' => [function (string $dir): void {
        config(['twentyone.stream.viewers.nginx_user' => 'nosuchuser_tos']);
    }, 'setfacl for nosuchuser_tos failed'],
    'a symlink' => [function (string $dir): void {
        mkdir($dir.'-target', 0700);
        symlink($dir.'-target', $dir);
    }, 'socket directory is a symlink, refused'],
]);

/**
 * A fake relay that accepts one event and keeps it in `$file`; [process, ws URL].
 *
 * @return array{0: resource, 1: string}
 */
function recordingRelay(string $file): array
{
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $file], [1 => ['pipe', 'w']], $pipes);

    return [$relay, 'ws://127.0.0.1:'.(int) fgets($pipes[1])];
}

/**
 * One daemon run of `$seconds` against a recording relay: the log and the
 * event the relay accepted (null when none).
 *
 * @return array{0: string, 1: array<string, mixed>|null}
 */
function streamRunRecorded(string $dir, int $run, float $seconds = 2): array
{
    [$relay, $url] = recordingRelay($dir."/event-{$run}.json");
    Artisan::call('twentyone:stream', ['--relays' => $url, '--stop-after' => $seconds]);
    $output = Artisan::output();
    proc_terminate($relay);
    proc_close($relay);
    $event = is_file($dir."/event-{$run}.json") ? json_decode((string) file_get_contents($dir."/event-{$run}.json"), true)[1] : null;

    return [$output, $event];
}

/**
 * The value of the first `$name` tag of an event.
 *
 * @param  array<string, mixed>  $event
 */
function eventTag(array $event, string $name): ?string
{
    return collect($event['tags'])->firstWhere(0, $name)[1] ?? null;
}

test('a restart within the resume window continues the session with the same starts; after it, a new session', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    $sessionFile = $this->dir.'/session.json';

    [, $first] = streamRunRecorded($this->dir, 1);
    $session = json_decode((string) file_get_contents($sessionFile), true);
    [$secondLog, $second] = streamRunRecorded($this->dir, 2);

    // The last live is 31 minutes old: a new session.
    File::put($sessionFile, json_encode(['starts' => $session['starts'] - 5000, 'lastLiveAt' => time() - 31 * 60]));
    [$thirdLog, $third] = streamRunRecorded($this->dir, 3);
    $after = json_decode((string) file_get_contents($sessionFile), true);

    expect([eventTag($first, 'status'), eventTag($second, 'status'), eventTag($third, 'status')])->toBe(['live', 'live', 'live'])
        ->and($session['starts'])->toBe((int) eventTag($first, 'starts'))
        ->and($session['lastLiveAt'])->toBe($first['created_at'])
        ->and(eventTag($second, 'starts'))->toBe(eventTag($first, 'starts'))
        ->and($second['created_at'])->toBeGreaterThan($first['created_at'])
        ->and($secondLog)->toContain('continuing the live session of '.$session['starts'])
        ->and((int) eventTag($third, 'starts'))->not->toBe($session['starts'] - 5000)
        ->and((int) eventTag($third, 'starts'))->toBeGreaterThanOrEqual(time() - 10)
        ->and($thirdLog)->not->toContain('continuing the live session')
        ->and($after['starts'])->toBe((int) eventTag($third, 'starts'));
});

test('a corrupt session file starts a new session, logged once, and is replaced', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    File::put($this->dir.'/session.json', '{"starts": 17');

    [$log, $event] = streamRunRecorded($this->dir, 1);

    expect(substr_count($log, 'session file '.$this->dir.'/session.json unreadable or not JSON'))->toBe(1)
        ->and($log)->toContain('starting a new session')
        ->and((int) eventTag($event, 'starts'))->toBeGreaterThanOrEqual(time() - 10)
        ->and(json_decode((string) file_get_contents($this->dir.'/session.json'), true)['starts'])->toBe((int) eventTag($event, 'starts'));
});

test('twentyone:stream:end publishes ended once for the session and clears it; with no relay accepting, the session stays', function () {
    $lastLive = time() + 5;
    File::put($this->dir.'/session.json', json_encode(['starts' => 1790000000, 'lastLiveAt' => $lastLive]));
    [$relay, $url] = recordingRelay($this->dir.'/ended.json');

    $exit = Artisan::call('twentyone:stream:end', ['--relays' => $url]);
    $output = Artisan::output();
    proc_terminate($relay);
    proc_close($relay);
    $event = json_decode((string) file_get_contents($this->dir.'/ended.json'), true)[1];

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'status=ended'))->toBe(1)
        ->and(eventTag($event, 'status'))->toBe('ended')
        ->and(eventTag($event, 'starts'))->toBe('1790000000')
        ->and($event['created_at'])->toBe($lastLive + 1)
        ->and(eventTag($event, 'ends'))->toBe((string) ($lastLive + 1))
        ->and(File::exists($this->dir.'/session.json'))->toBeFalse();

    File::put($this->dir.'/session.json', json_encode(['starts' => 1790000000, 'lastLiveAt' => 1790000100]));
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    config(['twentyone.stream.shutdown_publish_seconds' => 0.3]);

    expect(Artisan::call('twentyone:stream:end', ['--relays' => 'ws://'.stream_socket_get_name($silent, false)]))->toBe(1)
        ->and(File::exists($this->dir.'/session.json'))->toBeTrue();
});

test('a teaser whose numbers change mid-slide is rendered again with the new data', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    // Every SVG the daemon sends to rsvg-convert, kept (a frame with the same SVG is never sent twice).
    File::put($this->dir.'/rsvg-convert', "#!/bin/sh\ncat >> ".$this->dir."/renders\necho '<!--end-->' >> ".$this->dir."/renders\nprintf '\\211PNG'\n");
    chmod($this->dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $this->dir.'/rsvg-convert']);
    shortRotation();
    config(['twentyone.stream.rotation.teaser_seconds' => 4, 'twentyone.stream.stats.cache_seconds' => 1]);
    // Another process adds a result while the ladder teaser is on: after its first render.
    $added = false;
    View::composer('stream.rotation.a3-ladders', function () use (&$added): void {
        if (! $added) {
            $added = true;
            $user = User::factory()->create(['name' => 'Latecomer']);
            Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1234, 'results' => 3]);
        }
    });

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 5.5]);
    $output = Artisan::output();
    // The ladder teaser's frames, in the order they were sent.
    $ladders = array_values(array_filter(explode('<!--end-->', (string) @file_get_contents($this->dir.'/renders')), fn (string $svg): bool => str_contains($svg, '>Blitz 5+3<') && str_contains($svg, '>Daily<')));

    expect($output)->toContain('rotation: a3 teaser, rendered in')
        ->and($added)->toBeTrue()
        ->and(count($ladders))->toBeGreaterThanOrEqual(2)
        ->and($ladders[0])->not->toContain('Latecomer')
        ->and($ladders[count($ladders) - 1])->toContain('Latecomer');
});

test('the daemon keeps what it announces in the cache for the website, gone a minute after it stopped', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    $game = ChessGame::factory()->create();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3]);
    $announced = Cache::get('twentyone.stream.announced');
    $this->travel(61)->seconds();

    // The viewer socket is bound (no nginx sends anything): a count of 0, not null.
    expect($announced)->toBe(['viewers' => 0, ...StreamTexts::forGames([$game->fresh(['white', 'black'])], 0)])
        ->and(Cache::get('twentyone.stream.announced'))->toBeNull();
});

test('a failing cache store does not stop the stream, and is logged once', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    // A cache store that fails on every call (StreamStats and TournamentSlides count directly then).
    Cache::extend('down', fn () => Cache::repository(new class implements Store
    {
        public function __call(string $method, array $arguments): mixed
        {
            throw new RuntimeException('cache store down');
        }

        public function get($key): mixed
        {
            throw new RuntimeException('cache store down');
        }

        public function many(array $keys): array
        {
            throw new RuntimeException('cache store down');
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function putMany(array $values, $seconds): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function increment($key, $value = 1): int|bool
        {
            throw new RuntimeException('cache store down');
        }

        public function decrement($key, $value = 1): int|bool
        {
            throw new RuntimeException('cache store down');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function touch($key, $seconds): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function flush(): bool
        {
            throw new RuntimeException('cache store down');
        }

        public function getPrefix(): string
        {
            return '';
        }
    }));
    config(['cache.stores.down' => ['driver' => 'down'], 'cache.default' => 'down']);
    Cache::forgetDriver('down');

    $exit = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'announced state not cached: RuntimeException'))->toBe(1)
        ->and($output)->toContain('ffmpeg started');
});
