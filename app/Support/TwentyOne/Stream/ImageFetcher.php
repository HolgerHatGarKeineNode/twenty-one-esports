<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
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
 *     (CURLOPT_RESOLVE), so a second DNS answer cannot swap in 127.0.0.1;
 *   - redirects are not followed (a 3xx is a failure), one timeout for the
 *     whole request, the body is streamed and cut off past `max_bytes`;
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

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new StreamImageFailed('user info or a port other than 443');
        }

        $host = strtolower($parts['host']);
        $address = $this->publicAddress($host);
        $maxBytes = max(1, (int) config('twentyone.stream.images.max_bytes', 2 * 1024 * 1024));
        $seconds = max(1, (int) config('twentyone.stream.images.fetch_seconds', 5));
        // An IP literal is its own pin; a name is pinned to the address just checked.
        $curl = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];

        if ($address !== trim($host, '[]')) {
            $curl[CURLOPT_RESOLVE] = [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)];
        }

        try {
            $response = $this->http
                ->withHeaders(['Accept' => 'image/*'])
                ->connectTimeout(min(3, $seconds))
                ->timeout($seconds)
                ->withoutRedirecting()
                ->withOptions(['stream' => true, 'curl' => $curl])
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
            if (! Nip05Verifier::isPublicAddress($literal)) {
                throw new StreamImageFailed('address '.$literal.' is not public');
            }

            return $literal;
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new StreamImageFailed($host.' does not resolve');
        }

        foreach ($addresses as $address) {
            if (! Nip05Verifier::isPublicAddress($address)) {
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
