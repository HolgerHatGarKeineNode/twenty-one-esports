<?php

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
