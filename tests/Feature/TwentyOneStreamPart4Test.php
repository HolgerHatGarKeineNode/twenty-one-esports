<?php

use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\TwentyOne\Stream\Backoff;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\TwentyOne\Stream\ViewerCounter;
use App\Support\TwentyOne\Stream\ViewerFeed;
use App\Support\TwentyOne\Stream\ViewerSocket;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NineMensMorrisOn;

// One of four files the stream daemon tests are spread over (see tests/Support/twentyone_stream.php).
pest()->group('nostr');

beforeEach(fn () => twentyOneStreamUp($this));

afterEach(fn () => twentyOneStreamDown($this));

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

    expect($output)->toContain('rotation: a1 match game '.$blitz->id.', rendered in', 'rotation: a2 gallery, rendered in', 'rotation: d1 teaser, rendered in', 'rotation: d2 teaser')
        ->and($output)->not->toContain('rotation: promo loop')
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

test('a viewer socket that fails to read is bound again after a doubling backoff, and the count resumes', function () {
    $dir = config('twentyone.stream.viewers.dir');
    $path = $dir.'/viewers.sock';
    $sockets = [];
    $binds = [];
    $refuse = false;
    $logged = [];
    $bind = function () use ($dir, &$sockets, &$binds, &$refuse): ViewerSocket {
        $binds[] = true;

        return $refuse ? throw new RuntimeException('bind refused') : $sockets[] = ViewerSocket::bind($dir);
    };
    $feed = new ViewerFeed($bind, new ViewerCounter(3), new Backoff(2, 3), function (string $line) use (&$logged): void {
        $logged[] = $line;
    });
    $send = function (string $address) use ($path): void {
        $client = stream_socket_client('udg://'.$path);
        fwrite($client, "<190>Sep 27 12:00:00 hls: {$address}|Mozilla/5.0 Firefox/131.0|200");
        fclose($client);
    };
    $counts = [];

    $counts[100] = $feed->count(100, 100);
    $send('203.0.113.1');
    $counts[101] = $feed->count(101, 100);
    // The read fails: its stream is gone. The count goes off, the next bind is due in 2 s.
    $sockets[0]->close();
    $counts[102] = $feed->count(102, 100);
    // The retry at 104 fails as well (not logged); the next one waits 3 s (doubled, capped at the max).
    $refuse = true;
    $counts[103] = $feed->count(103, 100);
    $counts[104] = $feed->count(104, 100);
    $refuse = false;
    $counts[106] = $feed->count(106, 100);
    $counts[107] = $feed->count(107, 100);
    $send('203.0.113.2');
    $counts[108] = $feed->count(108, 100);
    $feed->close();

    expect($counts)->toBe([100 => 0, 101 => 1, 102 => null, 103 => null, 104 => null, 106 => null, 107 => 0, 108 => 1])
        ->and(count($binds))->toBe(3)
        ->and($logged)->toHaveCount(3)
        ->and($logged[0])->toBe('viewer count on '.$path)
        ->and($logged[1])->toBe('viewer count off: TypeError: stream_socket_recvfrom(): Argument #1 ($socket) must be an open stream resource; binding again in 2 s')
        ->and($logged[2])->toBe('viewer count back on '.$path)
        ->and(file_exists($path))->toBeFalse();
});

test('the live 30311 carries the rendered cover, the configured picture while none rendered', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    config(['twentyone.stream.cover.path' => $this->dir.'/cover.png']);

    // rsvg_convert is the PHP binary here: no cover renders.
    [$failedLog, $plain] = streamRunRecorded($this->dir, 1);

    fakeRenderer($this->dir);
    [$log, $event] = streamRunRecorded($this->dir, 2);

    expect(eventTag($plain, 'image'))->toBe(config('twentyone.stream.event.image'))
        ->and($failedLog)->toContain('cover not rendered, keeping the configured image')
        ->and($log)->toContain('cover: d1 '.route('stream.cover').'?v=')
        ->and(eventTag($event, 'image'))->toBe(route('stream.cover').'?v='.substr(hash('sha256', (string) file_get_contents($this->dir.'/cover.png')), 0, 16));
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

/* ---------- The board scene (plan "Mühle und Dame", P7) --------------------------------------------------------- */

test('a live board game without chess brings the board scene into the rotation', function () {
    Queue::fake();
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    NineMensMorrisOn::play();
    app(BoardGameService::class)->start(NineMensMorris::SLUG, User::factory()->create(), User::factory()->create());

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 6.5]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('rotation: d5 board, rendered in')
        ->and($output)->not->toContain('board poll failed');
});
