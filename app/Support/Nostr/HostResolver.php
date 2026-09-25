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
}
