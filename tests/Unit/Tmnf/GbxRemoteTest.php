<?php

use App\Support\Tmnf\GbxFault;
use App\Support\Tmnf\GbxProtocolError;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\GbxUnavailable;
use App\Support\Tmnf\TmnfChallenge;
use App\Support\Tmnf\TmnfServer;
use App\Support\Tmnf\XmlRpc;

/*
|--------------------------------------------------------------------------
| The GBXRemote 2 client against recorded frames
|--------------------------------------------------------------------------
|
| tests/Fixtures/tmnf/ holds frames byte for byte as Nadeo's TMNF dedicated
| server (build 2011-02-21, scripts/tmnf-server.sh) sent them on 2026-10-02:
| the greeting, the answers to Authenticate, EnableCallbacks,
| GetCurrentChallengeInfo (A01-Race), GetCurrentRanking (empty server),
| ChatSendServerMessage, a permission fault, and the callbacks PlayerChat
| (the server's own line), BeginChallenge and StatusChanged.
|
| A dedicated server cannot drive a car, so PlayerFinish, PlayerCheckpoint
| and a player's PlayerChat were built (`constructed-*.bin`) with the
| signatures of the server's ListCallbacks.html, in the recorded frames'
| exact layout: the same builder rebuilds the recorded server PlayerChat
| frame byte for byte.
|
| The frames go through a socket pair: the test writes the server's side,
| the client reads the other end as it reads TCP.
|
*/

test('a peer that does not greet with GBXRemote 2 is refused', function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, pack('V', 11).'GBXRemote 1');

    expect(fn () => GbxRemote::over($client, 1.0))->toThrow(GbxProtocolError::class);
});

test('the handshake, Authenticate and EnableCallbacks go out as the server expects and read its answers', function () {
    [$remote, $server] = tmnfClient(tmnfFrame('response-authenticate', 0x80000000), tmnfFrame('response-enable-callbacks', 0x80000001));
    $session = TmnfServer::over($remote);

    $session->authenticate('SuperAdmin', 'pass<&>word');
    $session->enableCallbacks();

    expect(tmnfSent($server))->toBe([
        [0x80000000, 'Authenticate', ['SuperAdmin', 'pass<&>word']],
        [0x80000001, 'EnableCallbacks', [true]],
    ]);
});

test('a refused call raises the server\'s fault with its code', function () {
    [$remote] = tmnfClient(tmnfFrame('response-fault-permission', 0x80000000));

    expect(fn () => $remote->call('GetCurrentChallengeInfo'))->toThrow(function (GbxFault $fault) {
        expect($fault->getMessage())->toBe('Permission denied.')->and($fault->getCode())->toBe(-1000);
    });
});

test('GetCurrentChallengeInfo reads the track of the recorded server: A01-Race with its UID and author time', function () {
    [$remote] = tmnfClient(tmnfFrame('response-current-challenge', 0x80000000));

    $challenge = TmnfServer::over($remote)->currentChallenge();

    expect($challenge)->toEqual(new TmnfChallenge('BeySZdnfuSh4nHY5xztiXLmlrXe', 'A01-Race', 'Challenges/League/A01-Race.Challenge.Gbx', 'Nadeo', 'Stadium', 24_540, 25_870, 3));
});

test('GetCurrentRanking of an empty server is an empty list, and ChatSendServerMessage is sent with its text', function () {
    [$remote, $server] = tmnfClient(tmnfFrame('response-current-ranking', 0x80000000), tmnfFrame('response-chat-send', 0x80000001));
    $session = TmnfServer::over($remote);

    expect($session->currentRanking(50, 0))->toBe([]);
    $session->chat('Week 41: A01-Race');

    expect(tmnfSent($server))->toBe([
        [0x80000000, 'GetCurrentRanking', [50, 0]],
        [0x80000001, 'ChatSendServerMessage', ['Week 41: A01-Race']],
    ]);
});

test('callbacks that arrive before an answer are queued and handed out in order, the answer still returned', function () {
    [$remote] = tmnfClient(
        tmnfFrame('callback-player-chat-server'),
        tmnfFrame('callback-begin-challenge'),
        tmnfFrame('response-chat-send', 0x80000000),
    );

    expect($remote->call('ChatSendServerMessage', ['hello']))->toBeTrue();

    $callbacks = $remote->callbacks(0.0);

    expect(array_map(fn ($callback) => $callback->method, $callbacks))->toBe(['TrackMania.PlayerChat', 'TrackMania.BeginChallenge'])
        ->and($callbacks[0]->params)->toBe([0, 'unnamed_172.26.0.2_2350', 'Link code test: 21-K7QX9P', false])
        ->and(TmnfChallenge::fromStruct($callbacks[1]->params[0])->name)->toBe('A01-Race')
        ->and($callbacks[1]->params[1])->toBeFalse();
});

test('PlayerFinish, PlayerCheckpoint and a player\'s PlayerChat read with their documented parameters', function () {
    [$remote] = tmnfClient(
        tmnfFrame('constructed-player-checkpoint'),
        tmnfFrame('constructed-player-finish'),
        tmnfFrame('constructed-player-chat-link'),
        tmnfFrame('callback-status-changed'),
    );

    $callbacks = $remote->callbacks(1.0);

    expect(array_map(fn ($callback) => [$callback->method, $callback->params], $callbacks))->toBe([
        ['TrackMania.PlayerCheckpoint', [236, 'satoshi_drives', 8421, 0, 0]],
        ['TrackMania.PlayerFinish', [236, 'satoshi_drives', 25_912]],
        ['TrackMania.PlayerChat', [236, 'satoshi_drives', 'link K7QX9P', false]],
        ['TrackMania.StatusChanged', [5, 'Running - Finish']],
    ]);
});

test('no callback within the wait is an empty list, not an error', function () {
    [$remote] = tmnfClient();

    expect($remote->callbacks(0.05))->toBe([])->and($remote->isOpen())->toBeTrue();
});

test('a server that closes the connection mid-frame makes the client unavailable and closed', function () {
    [$remote, $server] = tmnfClient(substr(tmnfFrame('constructed-player-finish'), 0, 40));
    fclose($server);
    $GLOBALS['tmnfServerEnds'] = [];

    expect(fn () => $remote->callbacks(1.0))->toThrow(GbxUnavailable::class)
        ->and($remote->isOpen())->toBeFalse();
});

test('an oversized frame is refused before it is read', function () {
    [$remote] = tmnfClient(pack('VV', GbxRemote::MAX_FRAME_BYTES + 1, 1));

    expect(fn () => $remote->callbacks(1.0))->toThrow(GbxProtocolError::class);
});

test('an answer with a document type is refused: no entity is ever expanded', function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><methodResponse><params><param><value><string>&e;</string></value></param></params></methodResponse>';

    expect(fn () => XmlRpc::decodeResponse($xml))->toThrow(GbxProtocolError::class);
});

test('values go out with their XML-RPC types and come back the same', function () {
    $params = [7, -3, true, false, 'Größe <&> "x"', 1.5, ['a', 2], ['Login' => 'x', 'Time' => 25_912], XmlRpc::base64("\x00\x01binary")];
    $sent = XmlRpc::encodeCall('Echo', $params);

    expect($sent)->toContain('<int>7</int>', '<boolean>1</boolean>', '<double>1.5</double>', '<base64>AAFiaW5hcnk=</base64>', '<struct><member><name>Login</name>')
        ->and(XmlRpc::decodeCall($sent))->toBe(['Echo', [7, -3, true, false, 'Größe <&> "x"', 1.5, ['a', 2], ['Login' => 'x', 'Time' => 25_912], "\x00\x01binary"]]);
});
