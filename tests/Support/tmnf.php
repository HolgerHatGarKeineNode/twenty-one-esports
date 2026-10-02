<?php

/*
 * Helpers of the TMNF tests (plan "Trackmania und Restposten"), loaded from
 * tests/Pest.php: frames from tests/Fixtures/tmnf/ (recorded from Nadeo's
 * dedicated server, or constructed in their layout: see
 * tests/Unit/Tmnf/GbxRemoteTest.php) played through a socket pair, and the
 * switch that registers the game.
 */

use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreAccountClaim;
use App\Models\ScoreRun;
use App\Models\User;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\TmnfCallback;
use App\Support\Tmnf\TmnfListener;
use App\Support\Tmnf\TmnfServer;
use App\Support\Tmnf\XmlRpc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

function tmnfFrame(string $name, ?int $handle = null): string
{
    $bytes = (string) file_get_contents(__DIR__.'/../Fixtures/tmnf/'.$name.'.bin');

    return $handle === null ? $bytes : substr($bytes, 0, 4).pack('V', $handle).substr($bytes, 8);
}

/**
 * A client connected to a fake server end that already sent the greeting and `$frames`.
 *
 * @return array{0: GbxRemote, 1: resource}
 */
function tmnfClient(string ...$frames): array
{
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, tmnfFrame('greeting').implode('', $frames));
    // The server end stays open for the test, also where the test does not hold it: a dropped end reads as a closed connection.
    $GLOBALS['tmnfServerEnds'][] = $server;

    return [GbxRemote::over($client, 2.0), $server];
}

/**
 * The frames the client wrote, decoded: [handle, method, params] each.
 *
 * @param  resource  $server
 * @return list<array{0: int, 1: string, 2: list<mixed>}>
 */
function tmnfSent($server): array
{
    stream_set_blocking($server, false);
    $bytes = (string) stream_get_contents($server);
    $calls = [];

    while (strlen($bytes) >= 8) {
        $head = unpack('Vsize/Vhandle', substr($bytes, 0, 8));
        [$method, $params] = XmlRpc::decodeCall(substr($bytes, 8, $head['size']));
        $calls[] = [$head['handle'], $method, $params];
        $bytes = substr($bytes, 8 + $head['size']);
    }

    return $calls;
}

/**
 * Registers TrackMania Nations Forever as the switch does at boot, with the
 * score routes routes/web.php adds then (the test app boots with it off).
 */
function tmnfOn(): void
{
    config(['esports.tmnf.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);

    if (! Route::has('scores.show')) {
        Route::middleware('web')->group(base_path('routes/score.php'));
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}

/**
 * A callback frame as the server sends it (a handle below 0x80000000), for
 * values a test makes up (a link code it was just issued).
 *
 * @param  list<mixed>  $params
 */
function tmnfCallbackFrame(string $method, array $params): string
{
    $xml = XmlRpc::encodeCall($method, $params);

    return pack('VV', strlen($xml), 1).$xml;
}

/** The UID of A01-Race, the track of the first TMNF week. */
const TMNF_A01 = 'BeySZdnfuSh4nHY5xztiXLmlrXe';

/**
 * A player who stored the TMNF login `$login`, linked to them when `$linked`.
 */
function tmnfPlayer(string $login, bool $linked = false, array $attributes = []): User
{
    $user = User::factory()->create(['gamer_tags' => ['tmnf' => $login], ...$attributes]);

    if ($linked) {
        ScoreAccountClaim::query()->create(['game' => TrackmaniaNationsForever::SLUG, 'account_id' => $login, 'user_id' => $user->id, 'confirmed_by_id' => null]);
    }

    return $user;
}

/**
 * A finish of `$login` in `$ms` on A01-Race at `$at` (now by default), handed to the listener as the server sends it:
 * BeginChallenge (the track as recorded from the real server), then PlayerFinish. Returns the run it stored, if any.
 */
function tmnfFinish(string $login, int $ms, ?CarbonImmutable $at = null): ?ScoreRun
{
    [$remote] = tmnfClient();
    $server = TmnfServer::over($remote);
    $listener = app(TmnfListener::class);
    $track = ['UId' => TMNF_A01, 'Name' => 'A01-Race', 'FileName' => 'Challenges/League/A01-Race.Challenge.Gbx', 'Author' => 'Nadeo',
        'Environnement' => 'Stadium', 'AuthorTime' => 24_540, 'GoldTime' => 25_870, 'NbCheckpoints' => 3];
    $before = (int) ScoreRun::query()->max('id');

    $listener->handle(new TmnfCallback('TrackMania.BeginChallenge', [$track, false, false]), $server);
    $listener->handle(new TmnfCallback('TrackMania.PlayerFinish', [236, $login, $ms]), $server, $at ?? CarbonImmutable::now());

    return ScoreRun::query()->where('id', '>', $before)->latest('id')->first();
}
