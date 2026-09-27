<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Polls a local TCP port until a relay subprocess (tests/Support/mini-relay.php,
 * `nak serve`) accepts connections.
 *
 * The inline loops this replaces (tests/Browser/ChatAndDailyTest.php,
 * tests/Browser/ShareTest.php) gave up silently after a fixed number of
 * attempts and let the test carry on as if the relay were already up. Under
 * host CPU load a subprocess can take longer than that fixed budget to bind
 * its socket, and the test then failed much later on an unrelated-looking
 * chat/relay assertion — the real cause (the relay was never reachable)
 * never surfaced. This throws instead, with the actual host/port in the
 * message, and the timeout is wide enough to absorb realistic host
 * contention rather than the process-startup time on an idle machine.
 */
final class WaitForPort
{
    public static function open(string $host, int $port, int $timeoutMs = 10_000, int $intervalMs = 100): void
    {
        $deadline = microtime(true) + $timeoutMs / 1000;

        do {
            $socket = @fsockopen($host, $port);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep($intervalMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Nothing answered on {$host}:{$port} within {$timeoutMs}ms (a relay subprocess did not start in time).");
    }
}
