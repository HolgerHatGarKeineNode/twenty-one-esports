<?php

namespace App\Support\Wallet;

/**
 * How NIP-47 events travel: a relay in production
 * ({@see WebsocketNwcTransport}), an in-process fake wallet in the feature
 * tests. The transport moves signed events only; it never sees a secret or a
 * plaintext.
 */
interface NwcTransport
{
    /**
     * Subscribe to $filter, publish $request, and return the first event the
     * relay delivers for the subscription that $accept takes, or null when
     * none arrives within $timeout seconds. An event $accept refuses (a
     * forgery, a stray answer) is skipped, not taken for the answer.
     *
     * @param  array<string, mixed>  $request  a signed event
     * @param  array<string, mixed>  $filter  a NIP-01 filter
     * @param  callable(array<string, mixed>): bool  $accept
     * @return array<string, mixed>|null
     */
    public function roundTrip(string $relay, array $request, array $filter, float $timeout, callable $accept): ?array;

    /**
     * The newest stored event matching $filter (REQ until EOSE), or null.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>|null
     */
    public function fetch(string $relay, array $filter, float $timeout): ?array;
}
