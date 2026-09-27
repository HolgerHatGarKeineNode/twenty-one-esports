<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
use GuzzleHttp\Handler\CurlHandler;
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
 *   - redirects are not followed (a 3xx is a failure); `fetch_seconds` is a
 *     hard total for the whole transfer (CURLOPT_TIMEOUT_MS), so a server
 *     that trickles bytes cannot hold the run; curl aborts past `max_bytes`
 *     (progress callback) and the body is checked again after;
 *   - the answer must be a 200 with an `image/*` content type.
 *
 * Every refusal throws StreamImageFailed with a reason that names the host,
 * never the query string (it may carry a token).
 */
class ImageFetcher
{
    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * The picture's bytes.
     *
     * @throws StreamImageFailed
     */
    public function fetch(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || $parts['host'] === '') {
            throw new StreamImageFailed('not an https URL');
        }

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== $this->port())) {
            throw new StreamImageFailed('user info or a port other than '.$this->port());
        }

        $host = strtolower($parts['host']);
        $address = $this->publicAddress($host);
        $maxBytes = max(1, (int) config('twentyone.stream.images.max_bytes', 2 * 1024 * 1024));
        $seconds = max(1, (int) config('twentyone.stream.images.fetch_seconds', 5));

        try {
            $response = $this->http
                ->setHandler(new CurlHandler)
                ->withHeaders(['Accept' => 'image/*'])
                ->connectTimeout(min(3, $seconds))
                ->timeout($seconds)
                ->withoutRedirecting()
                ->withOptions(['curl' => $this->curlOptions($host, $address, $seconds, $maxBytes)])
                ->get($url);
        } catch (Throwable $e) {
            throw new StreamImageFailed('request failed ('.self::withoutQuery($e->getMessage()).')', previous: $e);
        }

        if ($response->status() !== 200) {
            throw new StreamImageFailed('status '.$response->status().($response->redirect() ? ' (redirects are not followed)' : ''));
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
     * The curl options of one fetch: https only, the pin (an IP literal is its
     * own pin), a hard total deadline, and an abort past `$maxBytes`.
     *
     * @return array<int, mixed>
     */
    protected function curlOptions(string $host, string $address, int $seconds, int $maxBytes): array
    {
        $curl = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => $seconds * 1000,
            CURLOPT_CONNECTTIMEOUT_MS => min(3, $seconds) * 1000,
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
