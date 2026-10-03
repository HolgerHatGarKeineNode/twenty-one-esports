<?php

use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\ViewerSocket;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\TestSigner;

// One of four files the stream daemon tests are spread over (see tests/Support/twentyone_stream.php).
pest()->group('nostr');

beforeEach(fn () => twentyOneStreamUp($this));

afterEach(fn () => twentyOneStreamDown($this));

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
            ['p', $this->key->pubkey, '', 'host'],
        ]);

    expect(fn () => (new EventBuilder)->liveActivity($stream, $url.'?v=1', $this->key->pubkey, 'live', 1790000000))
        ->toThrow(InvalidArgumentException::class);
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

test('a running tournament holds the stream in its TV look; the next tournament waits until it ends', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    config(['twentyone.stream.rotation.tournament_seconds' => 1.5, 'twentyone.stream.rotation.running_tournament_seconds' => 6]);
    $running = runningChess(TournamentFormat::SingleElimination, 4);
    $next = openTournament();

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 7]);
    $output = Artisan::output();

    // A running tournament takes the stream (2026-10-03): live bracket, then who is still standing, no call to sign up meanwhile.
    // In its TV look: the bracket first, then the matches up now or the standings.
    expect($output)->toContain('rotation: tv1 tournament '.$running->id.', rendered in')->toMatch('/rotation: tv[23] tournament '.$running->id.', rendered in/')
        ->and(preg_match('/rotation: tv1 tournament/', $output, $four, PREG_OFFSET_CAPTURE))->toBe(1)
        ->and(preg_match('/rotation: tv[23] tournament/', $output, $five, PREG_OFFSET_CAPTURE))->toBe(1)
        ->and($four[0][1])->toBeLessThan($five[0][1])
        ->and($output)->not->toContain('not built')->not->toContain('failed')
        ->and($output)->not->toContain('tournament '.$next->id.',');
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
        ->and($output)->toContain('rotation: d1 teaser, rendered in', 'status=live')
        ->and($seen->count())->toBeGreaterThan(0)
        ->and(collect($seen->getArrayCopy())->pluck(1)->unique()->all())->toBe([null])
        ->and(collect($event['tags'])->where(0, 'current_participants')->all())->toBe([])
        ->and(file_exists(substr($socket, 0, ViewerSocket::MAX_PATH_BYTES)))->toBeFalse()
        ->and(file_exists($dir))->toBeFalse();
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
        // The music goes on where it was, instead of a new shuffle from its first track.
        ->and($secondLog)->toContain('music: continuing ')->not->toContain('music: new timeline')
        ->and((int) eventTag($third, 'starts'))->not->toBe($session['starts'] - 5000)
        ->and((int) eventTag($third, 'starts'))->toBeGreaterThanOrEqual(time() - 10)
        ->and($thirdLog)->not->toContain('continuing the live session')
        ->and($after['starts'])->toBe((int) eventTag($third, 'starts'));
});

test('a failing board read drops only the board scene: the chess games stay on show and it is no poll failure', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    fakeRenderer($this->dir);
    shortRotation();
    app()->instance(BoardScene::class, new class extends BoardScene
    {
        public function __construct() {}

        public function state(): string
        {
            throw new PDOException('SQLSTATE[HY000]: General error: 1 no such table: board_games');
        }
    });
    $blitz = ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();

    $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 5.5]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('rotation: a1 match game '.$blitz->id.', rendered in', 'rotation: a2 gallery, rendered in')
        // Logged once for the series, not on every poll; never counted as a failed database poll.
        ->and(substr_count($output, 'board poll failed, showing no board scene: PDOException'))->toBe(1)
        ->and($output)->not->toContain('database poll failed')
        ->and($output)->not->toContain('ffmpeg started mode=loop')
        ->and($output)->not->toContain('d5 board');
});
