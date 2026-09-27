<?php

namespace App\Support\Wallet;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;

/**
 * Where the server may open a NIP-47 websocket (P9 security gate F1): a
 * connection string names its relays, and an organizer types the string,
 * so a relay URL is untrusted input that makes the server connect.
 *
 * The rule is the one of the Lightning address fetches
 * (App\Support\Lightning\LightningAddress): `wss://` on port 443 of a DNS
 * name (no IP literal, no `localhost`, no credentials), every resolved
 * address public ({@see Nip05Verifier::isPublicAddress()}), and the socket
 * then pinned to the address that was checked ({@see PinnedStreamFactory}),
 * so a second DNS answer cannot redirect it. Outside production,
 * `esports.wallet.nwc_insecure_relays` names `host:port` pairs reached as
 * they are (the integration suite's local relay).
 */
final class RelayGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Where to connect for `$url`: the host and the checked address (`ip`
     * null for an allowed test relay), or null when it may not be reached.
     *
     * @return array{host: string, ip: string|null}|null
     */
    public function target(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host']) || preg_match('/\s/', $url) === 1) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        $hostPort = $host.':'.($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));

        if (! app()->isProduction() && in_array($hostPort, (array) config('esports.wallet.nwc_insecure_relays', []), true)) {
            return in_array($scheme, ['ws', 'wss'], true) ? ['host' => $host, 'ip' => null] : null;
        }

        if ($scheme !== 'wss' || (isset($parts['port']) && $parts['port'] !== 443) || Nip05Verifier::target('x@'.$host) === null) {
            return null;
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! Nip05Verifier::isPublicAddress($address)) {
                return null;
            }
        }

        return ['host' => $host, 'ip' => $addresses[0]];
    }
}
