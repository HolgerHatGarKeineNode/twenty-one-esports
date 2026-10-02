<?php

/*
 * Helpers of the TMNF tests (plan "Trackmania und Restposten"), loaded from
 * tests/Pest.php: frames from tests/Fixtures/tmnf/ (recorded from Nadeo's
 * dedicated server, or constructed in their layout: see
 * tests/Unit/Tmnf/GbxRemoteTest.php) played through a socket pair, and the
 * switch that registers the game.
 */

use App\Games\GameRegistry;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\XmlRpc;

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
 * Registers TrackMania Nations Forever as the switch does at boot.
 */
function tmnfOn(): void
{
    config(['esports.tmnf.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
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
