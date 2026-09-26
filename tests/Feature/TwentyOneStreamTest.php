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
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    putenv('TWENTYONE_NOSTR_NSEC');
    putenv('TWENTYONE_TEST_API_TOKEN');
});

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
    putenv('TWENTYONE_NOSTR_NSEC='.$this->nsec);
    putenv('TWENTYONE_TEST_API_TOKEN=not-for-children');
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
    File::ensureDirectoryExists($hlsDir);
    fakeFfmpeg($this->dir, "echo '#EXTM3U' > '{$hlsDir}/stream.m3u8'; echo x > '{$hlsDir}/seg-000000000.m4s'; exec sleep 30");

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
        ->and(File::glob($hlsDir.'/*'))->toBe([])
        ->and($output)->not->toContain($this->nsec)
        ->and($output)->not->toContain($this->key->secret)
        ->and(substr_count($output, 'ffmpeg started'))->toBe(1);
});
