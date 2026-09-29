<?php

namespace App\Support\Nostr;

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a player's NIP-05 address (`local@domain`) against
 * `https://domain/.well-known/nostr.json?name=local`.
 *
 * Server side on purpose: a "verified" mark the browser computed is a claim
 * the server cannot trust, and many domains send no CORS header, so a browser
 * could not read the document anyway.
 *
 * The address comes from a stranger's profile, so this is an outbound request
 * to a URL an attacker chooses. Fail closed at every step:
 *   - the domain must be a plain DNS name: no IP literal, port, user info or path;
 *   - EVERY address it resolves to must be public (no loopback, private,
 *     link-local, CGNAT, reserved or mapped ranges);
 *   - the connection is pinned to the address that was checked
 *     (CURLOPT_RESOLVE), so a second DNS answer cannot swap in 127.0.0.1;
 *   - https only, redirects are not followed (NIP-05 says fetchers MUST
 *     ignore them), a 4 s total deadline, at most 64 KB are read; all on the
 *     curl handler ({@see PinnedFetch}), where the pin and the deadline hold.
 */
class Nip05Verifier
{
    public const MAX_BYTES = 65536;

    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * Verify the user's current address and store the outcome.
     */
    public function verify(User $user): void
    {
        $nip05 = $user->nip05;

        if ($nip05 === null) {
            return;
        }

        $verified = $this->check($nip05, $user->pubkey);

        // The profile may have changed while the request ran.
        $fresh = $user->fresh();

        if ($fresh === null || $fresh->nip05 !== $nip05) {
            return;
        }

        $fresh->forceFill([
            'nip05_checked_at' => now(),
            'nip05_verified_at' => $verified ? now() : null,
        ])->save();
    }

    /**
     * Whether the address's nostr.json names this pubkey.
     */
    public function check(string $nip05, string $pubkey): bool
    {
        $target = self::target($nip05);

        if ($target === null || ! extension_loaded('curl')) {
            return false;
        }

        $address = $this->publicAddress($target['host']);
        $port = $this->port();

        if ($address === null) {
            Log::info('NIP-05 refused: domain does not resolve to a public address', ['host' => $target['host']]);

            return false;
        }

        try {
            // The curl handler (PinnedFetch, P47 audit F3): the stream handler ignored the pin and
            // treated the timeout as an idle time per read; curl keeps both, 4 s in total.
            $response = $this->http
                ->setHandler(PinnedFetch::handler())
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->withoutRedirecting()
                ->withOptions([...PinnedFetch::options($target['host'], $address, $port, self::MAX_BYTES), ...$this->extraOptions()])
                ->get('https://'.$target['host'].($port === 443 ? '' : ':'.$port).'/.well-known/nostr.json', ['name' => $target['local']]);

            if ($response->status() !== 200) {
                return false;
            }

            $json = $response->body();

            if (strlen($json) > self::MAX_BYTES) {
                return false;
            }
        } catch (Throwable $exception) {
            Log::info('NIP-05 check failed', ['host' => $target['host'], 'error' => $exception->getMessage()]);

            return false;
        }

        $document = json_decode($json, true);
        $names = is_array($document) ? ($document['names'] ?? null) : null;

        return is_array($names) && ($names[$target['local']] ?? null) === $pubkey;
    }

    /**
     * Local part and host of a NIP-05 address, or null when it is not a plain
     * `local@dns-name` (an IP literal, a port, a path, user info …).
     *
     * @return array{local: string, host: string}|null
     */
    public static function target(string $nip05): ?array
    {
        $nip05 = strtolower(trim($nip05));

        if (preg_match('/^([a-z0-9._-]{1,64})@((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59}))$/', $nip05, $parts) !== 1
            || strlen($parts[2]) > 253
        ) {
            return null;
        }

        return ['local' => $parts[1], 'host' => $parts[2]];
    }

    /**
     * Displayed form: `_@domain` is shown as the bare domain (NIP-05).
     */
    public static function display(string $nip05): string
    {
        return str_starts_with($nip05, '_@') ? substr($nip05, 2) : $nip05;
    }

    /**
     * Whether the address should be (re)checked.
     */
    public static function isDue(User $user): bool
    {
        return $user->nip05_checked_at === null
            || $user->nip05_checked_at->getTimestamp() < now()->subHours((int) config('esports.profiles.nip05_recheck_hours', 24))->getTimestamp();
    }

    /**
     * The first address of the host when ALL of them are public, else null.
     */
    private function publicAddress(string $host): ?string
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isAllowedAddress($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /** The only port a NIP-05 document comes from (a test hook: the local HTTPS server's port). */
    protected function port(): int
    {
        return 443;
    }

    /** Whether an address may be connected to at all (a test hook: loopback for the local server). */
    protected function isAllowedAddress(string $address): bool
    {
        return self::isPublicAddress($address);
    }

    /**
     * Further request options (a test hook: `verify` with the local server's CA file).
     *
     * @return array<string, mixed>
     */
    protected function extraOptions(): array
    {
        return [];
    }

    public static function isPublicAddress(string $address): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) counts as not global here, whatever it maps to.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $bytes = (string) inet_pton($address);

        if (strlen($bytes) === 4) {
            return ord($bytes[0]) < 224; // multicast and above
        }

        // PHP's global-range flag lets these through, yet each can carry or
        // reach an IPv4 address of any kind: NAT64 (64:ff9b::/96, 64:ff9b:1::/48),
        // 6to4 (2002::/16), Teredo (2001::/32); and multicast (ff00::/8).
        $hex = bin2hex($bytes);

        foreach (['0064ff9b', '2002', '20010000', 'ff'] as $prefix) {
            if (str_starts_with($hex, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
