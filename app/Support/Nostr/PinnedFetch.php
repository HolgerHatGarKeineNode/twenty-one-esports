<?php

namespace App\Support\Nostr;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\Response;

/**
 * Request options for an outbound GET to a host a stranger chose (a NIP-05
 * domain, a Lightning address), on Guzzle's curl handler (P47 security
 * audit, F1 and F3, re-audit N1):
 *
 * - The curl handler, never the stream handler (`'stream' => true`): only
 *   curl honours CURLOPT_RESOLVE, so only there does the connection go to the
 *   address the guard checked (measured: the stream handler connected to
 *   127.0.0.1 while the guard had resolved 1.1.1.1). And only there is
 *   `timeout` a TOTAL deadline (CURLOPT_TIMEOUT_MS); on the stream handler it
 *   is an idle time per read, and a server dripping one byte every few
 *   seconds held a worker for as long as it liked (measured 45 to 60 s).
 * - No decoding (`decode_content` false): Guzzle's default asks curl to
 *   inflate gzip, and curl then wrote a stacked gzip bomb into memory
 *   uncounted (928 bytes on the wire became 512 MB and a PHP fatal). The
 *   body is read as it came, and an encoded one is refused ({@see body()}).
 * - A raw curl progress function that returns non-zero past `$maxBytes`
 *   (downloaded or announced), which makes curl abort at once.
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
        $curl = [
            CURLOPT_NOPROGRESS => false,
            // Non-zero aborts the transfer: the body is past the cap.
            CURLOPT_PROGRESSFUNCTION => fn ($handle, $downloadTotal, $downloaded): int => $downloaded > $maxBytes || $downloadTotal > $maxBytes ? 1 : 0,
        ];

        if ($address !== null) {
            $curl[CURLOPT_RESOLVE] = [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)];
        }

        return ['decode_content' => false, 'curl' => $curl];
    }

    /**
     * The body as it came, or null when it is encoded (any `Content-Encoding`
     * but identity) or longer than `$maxBytes`. Read in bounded steps.
     */
    public static function body(Response $response, int $maxBytes): ?string
    {
        $encoding = strtolower(trim($response->header('Content-Encoding')));

        if ($encoding !== '' && $encoding !== 'identity') {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $bytes = '';

        while (! $stream->eof() && strlen($bytes) <= $maxBytes) {
            $bytes .= $stream->read(8192);
        }

        return strlen($bytes) > $maxBytes ? null : $bytes;
    }
}
