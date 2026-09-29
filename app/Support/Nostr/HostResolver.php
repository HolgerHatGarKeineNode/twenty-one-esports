<?php

namespace App\Support\Nostr;

/**
 * DNS lookup for {@see Nip05Verifier}, its own class so tests can answer
 * without the network (and point a name at a private address on purpose).
 */
class HostResolver
{
    /**
     * The A and AAAA addresses of a host name, empty when it does not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * {@see addresses()} within about `$seconds` (P47 re-audit N2): the
     * resolver's own timeout is set for this lookup only (glibc reads
     * `RES_OPTIONS` at every lookup): one attempt per query, the A and the
     * AAAA query each at most half the time, in whole seconds, at least one.
     * Measured with a DNS server that never answers: 10.01 s by default,
     * 1.00 s with `timeout:1 attempts:1`.
     *
     * @return list<string>
     */
    public function addressesWithin(string $host, float $seconds): array
    {
        $previous = getenv('RES_OPTIONS');
        $timeout = max(1, (int) floor($seconds / 2));
        putenv('RES_OPTIONS='.trim(($previous === false ? '' : $previous).' timeout:'.$timeout.' attempts:1'));

        try {
            return $this->addresses($host);
        } finally {
            putenv($previous === false ? 'RES_OPTIONS' : 'RES_OPTIONS='.$previous);
        }
    }
}
