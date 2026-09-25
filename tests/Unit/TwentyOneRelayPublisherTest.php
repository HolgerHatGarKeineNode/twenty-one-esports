<?php

use App\Support\TwentyOne\PublishResult;
use App\Support\TwentyOne\RelayPublisher;

/**
 * Relays that accept the TCP connection (the kernel completes it from the
 * listen backlog) but never answer the WebSocket handshake.
 *
 * @return list<resource>
 */
function silentRelays(int $count): array
{
    return array_map(fn () => stream_socket_server('tcp://127.0.0.1:0'), range(1, $count));
}

/**
 * @param  list<resource>  $servers
 * @return list<string>
 */
function relayUrlsOf(array $servers): array
{
    return array_map(fn ($server): string => 'ws://'.stream_socket_get_name($server, false), $servers);
}

function signedTestEvent(): array
{
    return ['id' => str_repeat('a', 64), 'pubkey' => str_repeat('b', 64), 'created_at' => 1790000000, 'kind' => 1, 'tags' => [], 'content' => '', 'sig' => str_repeat('c', 128)];
}

test('silent relays are waited for in parallel under one overall deadline', function () {
    $servers = silentRelays(3);
    $relays = relayUrlsOf($servers);

    $startedAt = microtime(true);
    $results = (new RelayPublisher)->publish(signedTestEvent(), $relays, 1.0);
    $elapsed = microtime(true) - $startedAt;

    expect($elapsed)->toBeLessThan(1.5)
        ->and(array_keys($results))->toBe($relays)
        ->and(array_filter($results, fn ($result): bool => $result->accepted))->toBe([]);
});

test('a publish in flight stops as soon as it is aborted', function () {
    $servers = silentRelays(2);
    $abortAt = microtime(true) + 0.2;

    $startedAt = microtime(true);
    $results = (new RelayPublisher)->publish(signedTestEvent(), relayUrlsOf($servers), 5.0, fn (): bool => microtime(true) >= $abortAt);

    expect(microtime(true) - $startedAt)->toBeLessThan(1.0)
        ->and(collect($results)->pluck('message')->unique()->all())->toBe(['aborted']);
});

/**
 * Publish once against tests/Support/fake-relay.php running the scenario.
 */
function publishToFakeRelay(string $scenario): PublishResult
{
    $process = proc_open([PHP_BINARY, __DIR__.'/../Support/fake-relay.php', $scenario], [1 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);
    $relay = 'ws://127.0.0.1:'.$port;

    try {
        return (new RelayPublisher)->publish(signedTestEvent(), [$relay], 5.0)[$relay];
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

test('relay frames with 16-bit and 64-bit lengths arrive intact', function (string $scenario, int $length) {
    $result = publishToFakeRelay($scenario);

    expect($result->accepted)->toBeFalse()
        ->and(strlen($result->message))->toBe($length)
        ->and($result->message)->toStartWith("reason:{$length}:");
})->with([
    '16-bit length' => ['ok16', 300],
    '64-bit length' => ['ok64', 70000],
]);

test('a relay answer split across many reads and frames is reassembled', function (string $scenario, int $length) {
    expect(publishToFakeRelay($scenario)->message)->toBe(str_pad("reason:{$length}:", $length, 'x'));
})->with([
    'dribbled in 7-byte writes, after a long NOTICE' => ['split', 400],
    'one message in three continuation frames' => ['continuation', 500],
]);

test('a relay ping is answered with a pong carrying the same payload', function () {
    expect(publishToFakeRelay('ping')->message)->toBe('pong received');
});

test('a relay close frame fails the publish with its reason', function () {
    $result = publishToFakeRelay('close');

    expect($result->accepted)->toBeFalse()
        ->and($result->message)->toBe('relay closed the connection: policy: '.str_pad('reason:100:', 100, 'x'));
});
