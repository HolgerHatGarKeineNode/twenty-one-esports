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
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

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
            ['image', 'https://esports.einundzwanzig.space/images/twentyone/banner.png'],
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

test('the supervisor survives an ffmpeg killed from outside and schedules a restart', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    $ffmpeg = $this->dir.'/ffmpeg';
    File::put($ffmpeg, "#!/bin/sh\nkill -KILL \$\$\n");
    chmod($ffmpeg, 0755);
    config(['twentyone.stream.ffmpeg' => $ffmpeg]);

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('ffmpeg exited by signal 9', 'restarting ffmpeg in 5 s');
});

test('the stream announces live, then ended, and never prints the secret key', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    // The fake ffmpeg "writes" a fresh playlist when it starts.
    Process::fake(function () {
        File::put(config('twentyone.stream.hls_dir').'/stream.m3u8', "#EXTM3U\n");

        return Process::describe()->runsFor(iterations: 20);
    });

    $exitCode = Artisan::call('twentyone:stream', ['--relays' => 'ws://127.0.0.1:1', '--stop-after' => 1]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('ffmpeg started', 'published kind 30311 status=live', 'published kind 30311 status=ended')
        ->and($output)->not->toContain($this->nsec)
        ->and($output)->not->toContain($this->key->secret);
    Process::assertRanTimes(fn ($process) => in_array('-stream_loop', $process->command, true), 1);
});
