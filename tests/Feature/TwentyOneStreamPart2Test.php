<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

// One of four files the stream daemon tests are spread over (see tests/Support/twentyone_stream.php).
pest()->group('nostr');

beforeEach(fn () => twentyOneStreamUp($this));

afterEach(fn () => twentyOneStreamDown($this));

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

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 10]);
    $output = Artisan::output();
    $countdowns = array_values(array_unique(file($this->dir.'/countdowns', FILE_IGNORE_NEW_LINES) ?: []));

    // Hero, bracket preview, how it runs (with the sign-up countdown), then the teasers.
    expect($output)->toContain('rotation: promo loop', 'rotation: ta1 tournament '.$tournament->id.', rendered in', 'rotation: ta2 tournament '.$tournament->id.', rendered in', 'rotation: ta3 tournament '.$tournament->id.', rendered in', 'rotation: d1 teaser')
        ->and(strpos($output, 'rotation: ta1 tournament'))->toBeLessThan(strpos($output, 'rotation: ta2 tournament'))
        ->and(strpos($output, 'rotation: ta2 tournament'))->toBeLessThan(strpos($output, 'rotation: ta3 tournament'))
        ->and(strpos($output, 'rotation: ta3 tournament'))->toBeLessThan(strpos($output, 'rotation: d1 teaser'))
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
        ->and($output)->toContain('rotation: promo loop', 'rotation: d1 teaser', 'rotation: d2 teaser')
        ->and($output)->not->toContain(' tournament ');
});

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
