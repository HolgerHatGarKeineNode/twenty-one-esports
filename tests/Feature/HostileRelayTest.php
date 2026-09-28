<?php

use App\Models\NostrEvent;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use Tests\Support\TestSigner;

/*
 * P45 security audit F1: a relay a player names in their DM relay list can
 * be hostile. Whatever it does, one delivery ends within relay_timeout_seconds
 * (plus a little) as a failed delivery, and never allocates what a frame
 * announces. Against tests/Support/hostile-relay.php on a local port.
 *
 * Measured before the fix (auditor's probe, relay_timeout_seconds 5): a
 * 1e9-byte frame header was a PHP fatal (memory), one byte a second held the
 * worker 49 s, a dripped upgrade 33 s and more.
 */

/**
 * @return array{result: array{accepted: bool, message: string}, seconds: float, peak_growth_mb: float}
 */
function publishToHostileRelay(string $mode, string $script = 'hostile-relay.php', array $extra = []): array
{
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/'.$script), $mode, ...$extra], [1 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    $event = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(1059, [['p', str_repeat('b', 64)]], 'x')));

    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $result = app(RelayPublisher::class)->publish($event, ['ws://127.0.0.1:'.$port])['ws://127.0.0.1:'.$port];
    $seconds = microtime(true) - $started;
    $growth = (memory_get_peak_usage(true) - $peakBefore) / 1048576;

    proc_terminate($relay);
    proc_close($relay);

    return ['result' => $result, 'seconds' => $seconds, 'peak_growth_mb' => $growth];
}

beforeEach(fn () => config(['esports.relay_timeout_seconds' => 2]));

test('a frame that announces 1e9 bytes fails at once, without allocating it', function () {
    $run = publishToHostileRelay('huge');

    expect($run['result']['accepted'])->toBeFalse()
        ->and($run['result']['message'])->toBe('error: relay frame larger than the 65536 bytes allowed')
        ->and($run['seconds'])->toBeLessThan(3.0)
        ->and($run['peak_growth_mb'])->toBeLessThan(32.0);
});

test('a frame dripped one byte a second ends at the deadline', function () {
    $run = publishToHostileRelay('drip');

    expect($run['result']['accepted'])->toBeFalse()
        ->and($run['seconds'])->toBeGreaterThan(1.5)->toBeLessThan(3.5);
});

test('an upgrade answer dripped one byte a second ends at the deadline', function () {
    $run = publishToHostileRelay('handshake');

    expect($run['result']['accepted'])->toBeFalse()
        ->and($run['seconds'])->toBeGreaterThan(1.5)->toBeLessThan(3.5);
});

test('100 KiB of NOTICE frames before any OK is more than the budget', function () {
    $run = publishToHostileRelay('flood');

    expect($run['result']['accepted'])->toBeFalse()
        ->and($run['result']['message'])->toContain((string) RelayPublisher::MAX_BYTES)
        ->and($run['seconds'])->toBeLessThan(3.0);
});

test('positive control: an honest relay still gets its OK true through the bounded connection', function () {
    $run = publishToHostileRelay('record', 'fake-relay.php', [storage_path('framework/testing/hostile-control-'.getmypid().'.json')]);

    expect($run['result'])->toBe(['accepted' => true, 'message' => ''])
        ->and($run['seconds'])->toBeLessThan(2.0);
});
