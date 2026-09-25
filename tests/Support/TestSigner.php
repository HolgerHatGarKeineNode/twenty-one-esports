<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? bin2hex(random_bytes(32));
        $this->pubkey = (new Key)->getPublicKey($this->secret);
    }

    /**
     * Give this user a fresh key and remember it for the browser test's
     * stubbed window.nostr (routes/testing.php, `__test/nostr/{user}/…`), in
     * the cache the in-process test server shares with the test.
     */
    public static function forBrowser(User $user): self
    {
        $signer = new self;
        Cache::forever('test-nostr-secret:'.$user->id, $signer->secret);
        $user->forceFill(['pubkey' => $signer->pubkey, 'npub' => NostrKeys::hexToNpub($signer->pubkey)])->save();

        return $signer;
    }

    /**
     * window.nostr for a browser context, backed by forBrowser()'s key on the
     * test server: signEvent and NIP-44 go through routes/testing.php.
     */
    public static function browserStub(User $user): string
    {
        return str_replace(['__PUBKEY__', '__USER__'], [$user->pubkey, (string) $user->id], <<<'JS'
            (() => {
                const post = (path, body) => fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
                    .then((r) => { if (!r.ok) throw new Error('stub signer ' + r.status); return r.json(); });
                window.nostr = {
                    getPublicKey: async () => '__PUBKEY__',
                    // Real NIP-07 extensions (nos2x, Alby) hand the draft to their
                    // content script via postMessage, i.e. a structured clone: a
                    // reactive Alpine proxy throws DataCloneError there. Do the same.
                    signEvent: async (draft) => post('/__test/nostr/__USER__/sign', structuredClone(draft)),
                    nip44: {
                        encrypt: (pubkey, text) => post('/__test/nostr/__USER__/nip44', { op: 'encrypt', pubkey, text }).then((r) => r.result),
                        decrypt: (pubkey, text) => post('/__test/nostr/__USER__/nip44', { op: 'decrypt', pubkey, text }).then((r) => r.result),
                    },
                };
            })();
            JS);
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
     * Sign server-prepared templates the way the browser module does.
     *
     * @param  list<array<string, mixed>>  $templates
     * @return list<array<string, mixed>>
     */
    public function signTemplates(array $templates): array
    {
        return array_map(fn (array $template): array => $this->sign(
            $template['kind'],
            $template['tags'],
            $template['content'],
            max($template['created_at'], now()->getTimestamp()),
        ), $templates);
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
