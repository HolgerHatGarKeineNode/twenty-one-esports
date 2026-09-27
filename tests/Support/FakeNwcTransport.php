<?php

namespace Tests\Support;

use App\Support\Wallet\NwcTransport;

/**
 * Carries NIP-47 events straight to a {@see FakeNwcWallet} in the same
 * process, as a relay would: the request event in, the wallet's answer event
 * out, checked by the league's client like any relay delivery. `forged`
 * events are delivered before the real answer, as a hostile relay could.
 */
final class FakeNwcTransport implements NwcTransport
{
    /** @var list<array<string, mixed>> */
    public array $forged = [];

    /** @var array<string, FakeNwcWallet> more wallets (a tournament's own), by pubkey */
    public array $others = [];

    /** @var array<string, true> wallets that do not answer at all (offline), by pubkey */
    public array $offline = [];

    /** Round trips and fetches asked of this transport (a refused relay must never get here). */
    public int $calls = 0;

    /** @var list<string|null> the wallet pubkey of every request round trip, in order (offline wallets too) */
    public array $requestsTo = [];

    public function __construct(public FakeNwcWallet $wallet) {}

    public function add(FakeNwcWallet $wallet): FakeNwcWallet
    {
        return $this->others[$wallet->pubkey] = $wallet;
    }

    public function roundTrip(string $relay, array $request, array $filter, float $timeout, callable $accept): ?array
    {
        $this->calls++;

        foreach ($this->forged as $event) {
            if ($accept($event)) {
                return $event;
            }
        }

        $to = null;

        foreach ((array) ($request['tags'] ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'p') {
                $to = $tag[1] ?? null;
            }
        }

        $this->requestsTo[] = $to;

        if (isset($this->offline[$to])) {
            return null;
        }

        $answer = ($this->others[$to] ?? $this->wallet)->handle($request);

        return $answer !== null && $accept($answer) ? $answer : null;
    }

    public function fetch(string $relay, array $filter, float $timeout): ?array
    {
        $this->calls++;
        $author = ((array) ($filter['authors'] ?? []))[0] ?? null;

        return in_array(13194, (array) ($filter['kinds'] ?? []), true) ? ($this->others[$author] ?? $this->wallet)->infoEvent() : null;
    }
}
