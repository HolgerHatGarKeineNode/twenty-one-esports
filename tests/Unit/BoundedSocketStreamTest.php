<?php

use App\Support\Nostr\BoundedSocketStream;
use App\Support\Nostr\RelayLimitExceeded;

/*
 * P45 re-audit: the wait a BoundedSocketStream sets on its socket never drops
 * below MIN_WAIT. A remainder of a few hundred nanoseconds rounded to
 * stream_set_timeout(0, 0), which on a TLS socket is a read without end.
 */

test('the wait is floored: a sub-microsecond remainder becomes MIN_WAIT, never zero', function () {
    expect(BoundedSocketStream::waitSeconds(0.0000004))->toBe(BoundedSocketStream::MIN_WAIT)
        ->and(BoundedSocketStream::waitSeconds(0.0))->toBe(BoundedSocketStream::MIN_WAIT)
        ->and(BoundedSocketStream::waitSeconds(2.5))->toBe(2.5)
        // What phrity turns it into: whole seconds and microseconds, never (0, 0).
        ->and((int) round(BoundedSocketStream::MIN_WAIT * 1_000_000))->toBeGreaterThan(0);
});

test('a silent socket ends at the deadline, and a read past it is refused at once', function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $stream = new BoundedSocketStream($client, microtime(true) + 0.3, 1024);

    $started = microtime(true);
    $read = $stream->read(10);

    expect($read)->toBe('')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);

    usleep(350_000);
    expect(fn () => $stream->read(10))->toThrow(RelayLimitExceeded::class, 'relay read past its deadline');

    fclose($server);
});

test('a read that would pass the byte budget is refused before anything is read', function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, str_repeat('x', 64));
    $stream = new BoundedSocketStream($client, microtime(true) + 2, 32);

    expect(fn () => $stream->read(1_000_000_000))->toThrow(RelayLimitExceeded::class, 'relay frame larger than the 32 bytes allowed')
        ->and($stream->read(16))->toBe(str_repeat('x', 16));

    fclose($server);
});
