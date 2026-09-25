<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\EventBuilder;
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

    foreach (['TWENTYONE_NOSTR_NSEC', 'TWENTYONE_TEST_API_TOKEN', 'FAKE_ENCODER_CAPTURE'] as $name) {
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

test('on stop the stream publishes ended first, in parallel, then stops ffmpeg and removes the playlist', function () {
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
        ->and(File::glob($hlsDir.'/{*,loop/*,scene/*}', GLOB_BRACE))->toBe([$hlsDir.'/loop', $hlsDir.'/scene', $hlsDir.'/stream.m3u8.state.json'])
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
        ->and($second[1])->toHaveCount(3)
        ->and(array_intersect($first[1], $second[1]))->toBe([])
        // Run 2 starts after the 3 segments of run 1: 0 → 3.
        ->and((int) $firstSequence[1])->toBe(0)
        ->and((int) $secondSequence[1])->toBe(3)
        ->and($published[2])->toMatch('/^#EXT-X-MAP:URI="loop\/\w+-init\.mp4"$/m')
        ->and($published[2])->not->toContain(explode('-', basename($first[1][0]))[0]);
});
