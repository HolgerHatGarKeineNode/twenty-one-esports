<?php

use App\Support\Tmnf\GbxFault;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\TmnfListener;
use App\Support\Tmnf\TmnfManialinks;
use App\Support\Tmnf\TmnfServer;

/*
|--------------------------------------------------------------------------
| Against the real TMNF dedicated server (group `tmnf`)
|--------------------------------------------------------------------------
|
| Nadeo's server in Docker, started by `scripts/tmnf-server.sh up` (LAN
| mode, A01-Race); the client reads TMNF_XMLRPC_* from .env. Skipped when
| the server does not answer, so the default suite never needs it. Run:
|
|     scripts/tmnf-server.sh up
|     vendor/bin/pest --group=tmnf
|     scripts/tmnf-server.sh down
|
| What a server without a driving player cannot show (PlayerFinish,
| PlayerCheckpoint, a player's chat line) is covered by the constructed
| frames in tests/Unit/Tmnf/GbxRemoteTest.php and TmnfListenTest.php.
|
*/

beforeEach(function () {
    $host = (string) config('esports.tmnf.xmlrpc.host');
    $port = (int) config('esports.tmnf.xmlrpc.port');

    try {
        GbxRemote::connect($host, $port, 1.0)->close();
    } catch (Throwable) {
        $this->markTestSkipped("No TMNF server answers on {$host}:{$port} (scripts/tmnf-server.sh up).");
    }

    if ((string) config('esports.tmnf.xmlrpc.password') === '') {
        $this->markTestSkipped('TMNF_XMLRPC_PASSWORD is not set.');
    }
});

test('the league logs in, enables callbacks and reads the track and the ranking', function () {
    $server = TmnfServer::open();

    $challenge = $server->currentChallenge();

    expect($challenge->uid)->toBe('BeySZdnfuSh4nHY5xztiXLmlrXe')
        ->and($challenge->name)->toBe('A01-Race')
        ->and($challenge->authorTime)->toBe(24_540)
        ->and($challenge->fileName)->toBe('Challenges/League/A01-Race.Challenge.Gbx')
        ->and($server->currentRanking())->toBeArray();

    $server->close();
})->group('tmnf');

test('a wrong SuperAdmin password is refused by the server', function () {
    $remote = GbxRemote::connect((string) config('esports.tmnf.xmlrpc.host'), (int) config('esports.tmnf.xmlrpc.port'));

    expect(fn () => TmnfServer::over($remote)->authenticate('SuperAdmin', 'not-the-password'))->toThrow(GbxFault::class, 'Password incorrect.');
})->group('tmnf');

test('a chat line the league sends comes back as a PlayerChat callback of the server itself', function () {
    $server = TmnfServer::open();
    $marker = 'league check '.bin2hex(random_bytes(4));

    $server->chat($marker);
    $deadline = microtime(true) + 5;
    $seen = null;

    while ($seen === null && microtime(true) < $deadline) {
        foreach ($server->callbacks(1.0) as $callback) {
            if ($callback->method === 'TrackMania.PlayerChat' && ($callback->params[2] ?? null) === $marker) {
                $seen = $callback->params;
            }
        }
    }

    expect($seen)->not->toBeNull()
        ->and($seen[0])->toBe(0)
        ->and($seen[3])->toBeFalse();

    $server->close();
})->group('tmnf');

test('the next round arrives as BeginChallenge with the track', function () {
    $server = TmnfServer::open();
    $control = GbxRemote::connect((string) config('esports.tmnf.xmlrpc.host'), (int) config('esports.tmnf.xmlrpc.port'));
    TmnfServer::over($control)->authenticate((string) config('esports.tmnf.xmlrpc.user'), (string) config('esports.tmnf.xmlrpc.password'));

    // A server just started (or just changed track) answers "Change in progress." until it plays (status 4, "Running - Play").
    $playing = microtime(true) + 30;
    while (($control->call('GetStatus')['Code'] ?? null) !== 4 && microtime(true) < $playing) {
        usleep(500_000);
    }

    $control->call('RestartChallenge');
    $deadline = microtime(true) + 20;
    $begun = null;

    while ($begun === null && microtime(true) < $deadline) {
        foreach ($server->callbacks(1.0) as $callback) {
            if ($callback->method === 'TrackMania.BeginChallenge') {
                $begun = $callback->params;
            }
        }
    }

    expect($begun)->not->toBeNull()
        ->and($begun[0]['UId'] ?? null)->toBe('BeySZdnfuSh4nHY5xztiXLmlrXe');

    $control->close();
    $server->close();
})->group('tmnf');

test('tmnf:listen connects to the real server and reads its track', function () {
    tmnfOn();

    $this->artisan('tmnf:listen', ['--seconds' => 2])
        ->expectsOutputToContain('Connected to the TMNF server, track A01-Race.')
        ->assertSuccessful();
})->group('tmnf');

test('the server takes the overlay pages without a fault, removes the note by its id and lists its players', function () {
    tmnfOn();
    $server = TmnfServer::open();
    $control = GbxRemote::connect((string) config('esports.tmnf.xmlrpc.host'), (int) config('esports.tmnf.xmlrpc.port'));
    TmnfServer::over($control)->authenticate((string) config('esports.tmnf.xmlrpc.user'), (string) config('esports.tmnf.xmlrpc.password'));
    $page = TmnfManialinks::page(
        TmnfManialinks::board('TWENTY ONE', 41, [['place' => 1, 'name' => '$o$f00<Satoshi> & "Hal"', 'time' => '0:25.912']]),
        TmnfManialinks::own(1, '0:25.912', true),
        TmnfManialinks::footer('esports.einundzwanzig.space'),
        TmnfManialinks::note('New personal best'),
    );

    $server->showPage($page);
    $server->showPage(TmnfManialinks::page(TmnfManialinks::remove(TmnfManialinks::ID_NOTE)));

    expect($server->players())->toBe([])
        ->and($control->call('GetManialinkPageAnswers'))->toBeArray()
        // A login that is not on the server is a fault, which the overlay reports and passes over.
        ->and(fn () => $server->showPage($page, 'nobody_here_'.bin2hex(random_bytes(3))))->toThrow(GbxFault::class);

    // The listener's own first showing on a session: board and footer for everyone, then the player list.
    $listener = app(TmnfListener::class);
    $listener->start($server);
    $listener->tick($server);

    expect($server->callbacks(0.5))->toBeArray();

    $control->close();
    $server->close();
})->group('tmnf');
