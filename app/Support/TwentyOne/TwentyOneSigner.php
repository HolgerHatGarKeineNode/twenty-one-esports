<?php

namespace App\Support\TwentyOne;

use App\Support\Nostr\NostrKeys;
use RuntimeException;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Throwable;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decode;

/**
 * Signs events with the TWENTY ONE Esports key.
 *
 * The secret is decoded strictly (human-readable part `nsec`, exactly 32
 * bytes, inside the secp256k1 range) and answered with null otherwise, so a
 * caller cannot sign with a half-parsed key. It is never exposed: there is no
 * getter, and debug output ({@see __debugInfo()}) only shows the pubkey.
 */
final class TwentyOneSigner
{
    /** Order of the secp256k1 group; a secret must be in [1, n - 1]. */
    private const CURVE_ORDER = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    public readonly string $pubkey;

    private function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        $this->pubkey = (new Key)->getPublicKey($secret);
    }

    /**
     * A signer for this nsec, or null when it is not a valid secret key.
     */
    public static function fromNsec(#[\SensitiveParameter] mixed $nsec): ?self
    {
        if (! is_string($nsec) || $nsec === '') {
            return null;
        }

        try {
            [$hrp, $data] = decode(trim($nsec));
            $bytes = convertBits($data, count($data), 5, 8, false);
        } catch (Throwable) {
            return null;
        }

        if ($hrp !== 'nsec' || count($bytes) !== 32) {
            return null;
        }

        $secret = vsprintf(str_repeat('%02x', 32), $bytes);

        if ($secret === str_repeat('0', 64) || strcmp($secret, self::CURVE_ORDER) >= 0) {
            return null;
        }

        return new self($secret);
    }

    /**
     * The signer for `twentyone.nostr.nsec`, checked against
     * `twentyone.nostr.npub` when that is set. The exception message names
     * the variable, never its value, so it is safe to print.
     *
     * @throws RuntimeException
     */
    public static function fromConfig(): self
    {
        $nsec = config('twentyone.nostr.nsec');

        if (! is_string($nsec) || trim($nsec) === '') {
            throw new RuntimeException('TWENTYONE_NOSTR_NSEC is not set.');
        }

        $signer = self::fromNsec($nsec) ?? throw new RuntimeException('TWENTYONE_NOSTR_NSEC is not a valid nsec.');
        $npub = config('twentyone.nostr.npub');

        if (is_string($npub) && trim($npub) !== '' && NostrKeys::npubToHex($npub) !== $signer->pubkey) {
            throw new RuntimeException('TWENTYONE_NOSTR_NSEC does not belong to TWENTYONE_NOSTR_NPUB.');
        }

        return $signer;
    }

    /**
     * Sign the event in place and return it as a NIP-01 array.
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}
     */
    public function sign(Event $event): array
    {
        (new Sign)->signEvent($event, $this->secret);

        /** @var array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string} */
        return $event->toArray();
    }

    /**
     * @return array{pubkey: string}
     */
    public function __debugInfo(): array
    {
        return ['pubkey' => $this->pubkey];
    }
}
