<?php

use App\Support\Wallet\WebsocketNwcTransport;

/*
 * P47 audit follow-up: the relay of a NIP-47 connection string comes from an
 * organizer, so it can be hostile. Whatever it does, one wallet round trip
 * ends by the transport's own deadline, and no frame larger than
 * WebsocketNwcTransport::MAX_FRAME_BYTES is ever allocated. The long wait for
 * a wallet's answer stays: an answer that comes late, but before the
 * deadline, still arrives. Against tests/Support/hostile-relay.php.
 *
 * Measured before the fix (2 s round trip): a 1e9-byte frame header was a
 * PHP fatal (memory), one byte a second held the round trip 50 s, a dripped
 * upgrade answer 60 s (until the hostile relay gave up), and a 12 MB message
 * in 60 KB fragments was taken whole.
 */

/**
 * @return array{answer: array<string, mixed>|null, seconds: float, peak_growth_mb: float}
 */
function nwcRoundTripAgainst(string $mode, float $timeout = 2.0, array $extra = []): array
{
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/hostile-relay.php'), $mode, ...$extra], [1 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    config(['esports.wallet.nwc_insecure_relays' => ["127.0.0.1:{$port}"]]);
    $request = ['id' => str_repeat('a', 64), 'kind' => 23194, 'content' => 'x'];

    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $answer = app(WebsocketNwcTransport::class)->roundTrip("ws://127.0.0.1:{$port}", $request, ['kinds' => [23195]], $timeout, fn (array $event): bool => true);
    $seconds = microtime(true) - $started;
    $growth = (memory_get_peak_usage(true) - $peakBefore) / 1048576;

    proc_terminate($relay);
    proc_close($relay);

    return ['answer' => $answer, 'seconds' => $seconds, 'peak_growth_mb' => $growth];
}

// A transport that took the publisher's short relay timeout instead of its own would lose the late answer.
beforeEach(fn () => config(['esports.relay_timeout_seconds' => 1]));

test('a frame that announces 1e9 bytes ends the round trip at once, without allocating it', function () {
    $run = nwcRoundTripAgainst('huge');

    expect($run['answer'])->toBeNull()
        ->and($run['seconds'])->toBeLessThan(1.5)
        ->and($run['peak_growth_mb'])->toBeLessThan(32.0);
});

test('one frame beyond the frame cap ends the round trip at once, though the connection budget is larger', function () {
    expect(WebsocketNwcTransport::MAX_FRAME_BYTES)->toBeLessThan(100_000)
        ->and(WebsocketNwcTransport::MAX_BYTES)->toBeGreaterThan(100_000);

    $run = nwcRoundTripAgainst('big');

    expect($run['answer'])->toBeNull()
        ->and($run['seconds'])->toBeLessThan(1.5);
});

test('a frame dripped one byte a second ends at the round trip deadline', function () {
    $run = nwcRoundTripAgainst('drip');

    expect($run['answer'])->toBeNull()
        ->and($run['seconds'])->toBeGreaterThan(1.5)->toBeLessThan(3.0);
});

test('an upgrade answer dripped one byte a second ends at the round trip deadline', function () {
    $run = nwcRoundTripAgainst('handshake');

    expect($run['answer'])->toBeNull()
        ->and($run['seconds'])->toBeGreaterThan(1.5)->toBeLessThan(3.0);
});

test('a message split into small fragments cannot grow past the connection budget', function () {
    $run = nwcRoundTripAgainst('fragments');

    expect($run['answer'])->toBeNull()
        ->and($run['seconds'])->toBeLessThan(1.5)
        ->and($run['peak_growth_mb'])->toBeLessThan(8.0);
});

test('positive control: a wallet answer that comes after a quiet wait longer than the publisher timeout still arrives', function () {
    $run = nwcRoundTripAgainst('answer', 5.0, ['3']);

    expect($run['answer'])->toMatchArray(['kind' => 23195, 'content' => 'late'])
        ->and($run['seconds'])->toBeGreaterThan(2.9)->toBeLessThan(4.5);
});
