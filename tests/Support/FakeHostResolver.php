<?php

namespace Tests\Support;

use App\Support\Nostr\HostResolver;

/**
 * DNS without the network for the wallet tests: every name resolves to a
 * public address unless `$answers` says otherwise (a private one on purpose).
 * `$lookups` lists every name asked for.
 */
final class FakeHostResolver extends HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(public array $answers = []) {}

    public function addresses(string $host): array
    {
        $this->lookups[] = $host;

        return $this->answers[$host] ?? ['93.184.215.14'];
    }
}
