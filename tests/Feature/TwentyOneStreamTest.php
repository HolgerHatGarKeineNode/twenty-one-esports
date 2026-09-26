<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
    ]);

    File::ensureDirectoryExists($this->dir.'/music');

    foreach (['a__v1', 'a__v2', 'b__v1', 'c__v1'] as $track) {
        File::put($this->dir."/music/{$track}.m4a", 'aac');
    }
});

afterEach(function () {
    File::deleteDirectory($this->dir);

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

test('on stop the stream publishes ended first, in parallel, then stops ffmpeg and keeps the playlist', function () {
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

    preg_match('/status=live id=\w+ created_at=(\d+)/', $output, $live);
    preg_match('/status=ended id=\w+ created_at=(\d+) to 0\/3 relays/', $output, $ended);

    expect($exitCode)->toBe(0)
        // live (0.3 s budget) + stop-after (1 s) + ended (0.3 s budget), not 3 × per
        // relay. Publish timeouts are kept short (0.3 s, not 1 s) precisely so this
        // budget has real headroom over the deterministic sum (~1.6 s): measured
        // 1.85 s idle / up to 2.14 s under 2× CPU oversubscription (24 and 48 busy
        // `yes` processes on a 24-core box), so 3.0 s still leaves ~40 % margin
        // instead of the ~0 % margin the old 1 s timeouts left against 3.5 s.
        ->and($elapsed)->toBeLessThan(3.0)
        ->and($live)->not->toBe([])
        ->and($ended)->not->toBe([])
        ->and((int) $ended[1])->toBeGreaterThan((int) $live[1])
        ->and(strpos($output, 'status=ended'))->toBeLessThan(strpos($output, 'ffmpeg stopped'))
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
 * A SceneSource whose liveGame() answers from a script: a ChessGame, null,
 * or a Throwable to throw, one entry per poll (the last one repeats).
 *
 * @param  list<ChessGame|Throwable|null>  $answers
 */
function scriptedSource(array $answers): void
{
    $source = Mockery::mock(SceneSource::class, [app(ChessGameService::class), app(GameRegistry::class)])->makePartial();
    $source->shouldReceive('liveGame')->andReturnUsing(function () use (&$answers) {
        $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    });
    app()->instance(SceneSource::class, $source);
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
