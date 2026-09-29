<?php

namespace App\Support\Nostr;

use GuzzleHttp\Handler\CurlHandler;
use RuntimeException;

/**
 * Request options for an outbound GET to a host a stranger chose (a NIP-05
 * domain, a Lightning address), on Guzzle's curl handler (P47 security
 * audit, F1 and F3):
 *
 * - The curl handler, never the stream handler (`'stream' => true`): only
 *   curl honours CURLOPT_RESOLVE, so only there does the connection go to the
 *   address the guard checked (measured: the stream handler connected to
 *   127.0.0.1 while the guard had resolved 1.1.1.1). And only there is
 *   `timeout` a TOTAL deadline (CURLOPT_TIMEOUT_MS); on the stream handler it
 *   is an idle time per read, and a server dripping one byte every few
 *   seconds held a worker for as long as it liked (measured 45 to 60 s).
 * - A `progress` callback that aborts the transfer as soon as more than
 *   `$maxBytes` arrived or were announced, so the buffered body stays small.
 */
final class PinnedFetch
{
    public static function handler(): CurlHandler
    {
        return new CurlHandler;
    }

    /**
     * @param  string|null  $address  the checked address to pin `$host` to; null for no pin (the test hosts)
     * @return array<string, mixed>
     */
    public static function options(string $host, ?string $address, int $port, int $maxBytes): array
    {
        $options = [
            'progress' => function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                if ($downloaded > $maxBytes || $downloadTotal > $maxBytes) {
                    throw new RuntimeException('response larger than '.$maxBytes.' bytes');
                }
            },
        ];

        if ($address !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]];
        }

        return $options;
    }
}
