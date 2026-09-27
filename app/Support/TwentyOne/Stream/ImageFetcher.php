<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Loads a player's kind-0 `picture` for the stream's avatar cache
 * (StreamImageBuilder). The URL comes from a stranger's profile, so this is
 * an outbound request to a place an attacker chooses. It fails closed at
 * every step, like {@see Nip05Verifier}:
 *   - https only, no user info, no port but 443;
 *   - an IP literal must be public; a name must resolve, and EVERY address
 *     it resolves to must be public ({@see Nip05Verifier::isPublicAddress()});
 *   - the connection is pinned to the address that was checked
 *     (CURLOPT_RESOLVE), so a second DNS answer cannot swap in 127.0.0.1.
 *     The curl handler is forced: with `allow_url_fopen` on, Guzzle would
 *     hand a `stream` request to its StreamHandler, which ignores every
 *     curl option, the pin included (security audit 2026-09-27: the guard
 *     saw 8.8.8.8, the connection went to 127.0.0.1);
 *   - at most MAX_REDIRECTS redirects are followed, by this class and not
 *     by curl: every hop's Location (resolved against the URL it came from)
 *     passes all of the checks above again and gets its own pin, before any
 *     request goes there (Blossom hosts such as blossom.primal.net answer a
 *     302 to their CDN); `fetch_seconds` is a hard total across all hops
 *     (CURLOPT_TIMEOUT_MS of what is left), so a server that trickles bytes
 *     or a chain of slow redirects cannot hold the run; curl aborts past
 *     `max_bytes` (progress callback) and the body is checked again after;
 *   - the answer must be a 200 with an `image/*` content type.
 *
 * Every refusal throws StreamImageFailed with a reason that names the host,
 * never the query string (it may carry a token).
 */
class ImageFetcher
{
    /** Redirects followed at most; one more is a failure (a loop ends here too). */
    public const MAX_REDIRECTS = 3;

    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * The picture's bytes.
     *
     * @throws StreamImageFailed
     */
    public function fetch(string $url): string
    {
        $maxBytes = max(1, (int) config('twentyone.stream.images.max_bytes', 8 * 1024 * 1024));
        $seconds = max(1, (int) config('twentyone.stream.images.fetch_seconds', 5));
        $deadline = microtime(true) + $seconds;

        for ($hop = 0; ; $hop++) {
            try {
                [$host, $address] = $this->target($url);
            } catch (StreamImageFailed $e) {
                throw $hop === 0 ? $e : new StreamImageFailed('redirect to '.self::loggable($url).' refused: '.$e->getMessage());
            }

            $leftMs = (int) floor(($deadline - microtime(true)) * 1000);

            if ($leftMs < 1) {
                throw new StreamImageFailed('no time left for redirect '.$hop.' after '.$seconds.' s');
            }

            try {
                $response = $this->http
                    ->setHandler(new CurlHandler)
                    ->withHeaders(['Accept' => 'image/*'])
                    ->connectTimeout(min(3000, $leftMs) / 1000)
                    ->timeout($leftMs / 1000)
                    ->withoutRedirecting()
                    ->withOptions(['curl' => $this->curlOptions($host, $address, $leftMs, $maxBytes)])
                    ->get($url);
            } catch (Throwable $e) {
                throw new StreamImageFailed('request failed ('.self::withoutQuery($e->getMessage()).')', previous: $e);
            }

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            if ($hop >= self::MAX_REDIRECTS) {
                throw new StreamImageFailed('more than '.self::MAX_REDIRECTS.' redirects');
            }

            $url = $this->location($url, $response->header('Location'));
        }

        if ($response->status() !== 200) {
            throw new StreamImageFailed('status '.$response->status());
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if (! str_starts_with($type, 'image/')) {
            throw new StreamImageFailed('content type "'.substr($type, 0, 60).'" is not an image');
        }

        $length = $response->header('Content-Length');

        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw new StreamImageFailed('larger than '.$maxBytes.' bytes');
        }

        try {
            $body = $response->toPsrResponse()->getBody();
            $bytes = '';

            while (! $body->eof() && strlen($bytes) <= $maxBytes) {
                $bytes .= $body->read(65536);
            }

            $body->close();
        } catch (Throwable $e) {
            throw new StreamImageFailed('reading the body failed ('.self::withoutQuery($e->getMessage()).')', previous: $e);
        }

        if (strlen($bytes) > $maxBytes) {
            throw new StreamImageFailed('larger than '.$maxBytes.' bytes');
        }

        return $bytes;
    }

    /**
     * The checks of one URL (the first or a redirect's): https, no user info,
     * the one port, a public address to pin; [lower-case host, address].
     *
     * @return array{0: string, 1: string}
     *
     * @throws StreamImageFailed
     */
    private function target(string $url): array
    {
        // The HTTP client expands `{…}` as a URI template after this check, and backslashes,
        // whitespace and control bytes are read differently by parse_url and curl: any of
        // them could move the host past what the guard saw, so such a URL is never requested.
        if (preg_match('/[{}\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            throw new StreamImageFailed('URL contains a brace, backslash, whitespace or control byte');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || $parts['host'] === '') {
            throw new StreamImageFailed('not an https URL');
        }

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== $this->port())) {
            throw new StreamImageFailed('user info or a port other than '.$this->port());
        }

        $host = strtolower($parts['host']);

        return [$host, $this->publicAddress($host)];
    }

    /**
     * A redirect's target: its Location resolved against the URL that answered.
     *
     * @throws StreamImageFailed
     */
    private function location(string $from, string $location): string
    {
        if (trim($location) === '') {
            throw new StreamImageFailed('a redirect without a Location');
        }

        try {
            return (string) UriResolver::resolve(new Uri($from), new Uri(trim($location)));
        } catch (Throwable) {
            throw new StreamImageFailed('a redirect to an unparsable Location');
        }
    }

    /**
     * The curl options of one request: https only, the pin (an IP literal is
     * its own pin), what is left of the total deadline, and an abort past
     * `$maxBytes`.
     *
     * @return array<int, mixed>
     */
    protected function curlOptions(string $host, string $address, int $timeoutMs, int $maxBytes): array
    {
        $curl = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
            CURLOPT_NOPROGRESS => false,
            // Non-zero aborts the transfer: the body is past the cap.
            CURLOPT_PROGRESSFUNCTION => fn ($handle, $downloadTotal, $downloaded): int => $downloaded > $maxBytes || $downloadTotal > $maxBytes ? 1 : 0,
        ];

        if ($address !== trim($host, '[]')) {
            $curl[CURLOPT_RESOLVE] = [$host.':'.$this->port().':'.(str_contains($address, ':') ? '['.$address.']' : $address)];
        }

        return $curl;
    }

    /**
     * The only port a picture may come from.
     */
    protected function port(): int
    {
        return 443;
    }

    /**
     * Whether an address may be connected to at all.
     */
    protected function isAllowedAddress(string $address): bool
    {
        return Nip05Verifier::isPublicAddress($address);
    }

    /**
     * A URL for a log line: scheme, host and path, no query, no fragment,
     * no user info.
     */
    public static function loggable(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '(unparsable URL)';
        }

        return ($parts['scheme'] ?? '?').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    /**
     * The address to pin: the IP literal itself, or the first address of the
     * name when ALL of its addresses are public.
     *
     * @throws StreamImageFailed
     */
    private function publicAddress(string $host): string
    {
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isAllowedAddress($literal)) {
                throw new StreamImageFailed('address '.$literal.' is not public');
            }

            return $literal;
        }

        // A host whose last label is a number (`127.1`, `0x7f000001`, `2130706433`) is no name for
        // the guard but an IPv4 address for curl, which would skip the pin (WHATWG "ends in a number").
        if (preg_match('/(?:^|\.)(?:0x[0-9a-f]*|[0-9]+)\.?$/i', $literal) === 1) {
            throw new StreamImageFailed('host '.$literal.' ends in a number but is no plain IP address');
        }

        // Only plain ASCII labels: curl rewrites IDN, percent-escapes and a trailing dot on its
        // own, and a host it rewrites no longer matches the CURLOPT_RESOLVE pin, so curl would
        // resolve it again itself, past the guard.
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $literal) !== 1) {
            throw new StreamImageFailed('host '.$literal.' is no plain ASCII name');
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new StreamImageFailed($host.' does not resolve');
        }

        foreach ($addresses as $address) {
            if (! $this->isAllowedAddress($address)) {
                throw new StreamImageFailed($host.' resolves to '.$address.', which is not public');
            }
        }

        return $addresses[0];
    }

    /**
     * An exception message with every URL's query string cut off.
     */
    private static function withoutQuery(string $message): string
    {
        return (string) preg_replace('#(https?://[^\s?\#"\']*)[?\#][^\s"\']*#i', '$1', substr($message, 0, 300));
    }
}
