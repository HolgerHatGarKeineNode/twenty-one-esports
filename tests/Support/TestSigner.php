<?php

namespace Tests\Support;

use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * A throwaway Nostr key per test. The secret is 32 random bytes padded to 64
 * hex characters (Key::generatePrivateKey() drops leading zeros).
 */
final class TestSigner
{
    public readonly string $secret;

    public readonly string $pubkey;

    public function __construct()
    {
        $this->secret = bin2hex(random_bytes(32));
        $this->pubkey = (new Key)->getPublicKey($this->secret);
    }

    /**
     * @param  list<list<string>>  $tags
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}
     */
    public function sign(int $kind, array $tags = [], string $content = '', ?int $createdAt = null): array
    {
        $event = (new Event)
            ->setKind($kind)
            ->setTags($tags)
            ->setContent($content)
            ->setCreatedAt($createdAt ?? now()->getTimestamp());

        (new Sign)->signEvent($event, $this->secret);

        /** @var array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string} */
        return $event->toArray();
    }

    /**
     * A NIP-98-style login event over the given challenge.
     *
     * @return array<string, mixed>
     */
    public function loginEvent(string $challenge, ?string $url = null, ?int $createdAt = null, int $kind = 27235): array
    {
        return $this->sign($kind, [
            ['u', $url ?? route('auth.nostr.login')],
            ['method', 'POST'],
            ['challenge', $challenge],
        ], createdAt: $createdAt);
    }
}
