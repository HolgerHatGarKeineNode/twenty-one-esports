<?php

use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\Stream\ViewerCounter;
use App\Support\TwentyOne\Stream\ViewerSocket;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use Tests\Support\BlockfillOn;

// One of four files the stream daemon tests are spread over (see tests/Support/twentyone_stream.php).
pest()->group('nostr');

beforeEach(fn () => twentyOneStreamUp($this));

afterEach(fn () => twentyOneStreamDown($this));

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

test('without games the stream starts on the promo loop and goes on with the teasers', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);
    $output = Artisan::output();

    expect($output)->toContain('promo length not readable, the loop slot lasts 1 s', 'rotation: promo loop', 'ffmpeg started mode=loop', 'rotation: d1 teaser', 'ffmpeg started mode=scene')
        ->and(strpos($output, 'rotation: promo loop'))->toBeLessThan(strpos($output, 'rotation: d1 teaser'));
});

test('an encoder that stops writing segments is restarted by the watchdog', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    setChildEnv('FAKE_ENCODER_SEGMENTS', '0');
    config(['twentyone.stream.watchdog_seconds' => 2]);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4]);

    expect(Artisan::output())->toMatch('/ffmpeg mode=loop wrote no segment for \d+ s, restarting it/');
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
    // Another process adds a result while the ladder teaser is on: after its first render. It comes
    // after the loop (1 s) and the three teasers of every round (pots, cups, a pride moment: 4 s each).
    $added = false;
    View::composer('stream.rotation.a3-ladders', function () use (&$added): void {
        if (! $added) {
            $added = true;
            $user = User::factory()->create(['name' => 'Latecomer']);
            Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1234, 'results' => 3]);
        }
    });

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 17.5]);
    $output = Artisan::output();
    // The ladder teaser's frames, in the order they were sent.
    $ladders = array_values(array_filter(explode('<!--end-->', (string) @file_get_contents($this->dir.'/renders')), fn (string $svg): bool => str_contains($svg, 'data-unit="sub-640"')));

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

test('the 30311 title and summary take turns every few minutes and name every game switched on, not chess alone', function () {
    BlockfillOn::play();
    tmnfOn();
    $minutes = (int) config('twentyone.stream.texts.rotate_minutes');
    $scene = ['title' => 'Live now: 15 chess games', 'summary' => 'Alice vs Bob and 14 more: live chess'];

    $turns = collect(range(0, 7))->map(fn (int $turn): array => StreamTexts::rotate($scene, 1_000_000_000 - (1_000_000_000 % ($minutes * 60)) + $turn * $minutes * 60));
    $titles = $turns->pluck('title')->unique()->values();
    $summaries = $turns->pluck('summary')->implode(' ');

    expect($minutes)->toBeGreaterThan(0)
        ->and($titles->count())->toBeGreaterThanOrEqual(4)
        ->and($titles)->toContain('Live now: 15 chess games')
        ->and($titles->implode(' '))->toContain('TrackMania')->toContain('Blockfill')
        ->and($summaries)->toContain('Rocket League')->toContain('TrackMania')->toContain('Blockfill')->toContain('Chess')
        ->and($summaries)->not->toContain('#')->not->toContain('in development');

    // Back to back, a title never repeats.
    $turns->pluck('title')->sliding(2)->each(fn ($pair) => expect($pair->first())->not->toBe($pair->last()));

    // Without a scene (the loop) the general texts rotate alone.
    expect(StreamTexts::rotate(null, 1_000_000_000)['title'])->not->toBe('');
});
