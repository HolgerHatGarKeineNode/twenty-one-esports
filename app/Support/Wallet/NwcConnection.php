<?php

namespace App\Support\Wallet;

use App\Support\Nostr\NostrKeys;
use LogicException;
use swentel\nostr\Key\Key;
use Throwable;

/**
 * One NIP-47 connection, parsed from a `nostr+walletconnect://` URI:
 * the wallet service's pubkey, its relay, and the client secret the league
 * signs requests with and decrypts answers with.
 *
 * The secret is the key to the wallet. It is kept in a
 * \SensitiveParameterValue and only unwrapped inside {@see withSecret()};
 * there is no getter. var_dump, var_export, print_r, dump() and array casts
 * see the pubkeys and the relay only, serialize() and json_encode() throw or
 * see nothing, and every function that takes the URI or the secret marks it
 * #[\SensitiveParameter], so a stack trace with arguments shows no value. A
 * URI that does not parse is null, and no message ever repeats the URI.
 */
final class NwcConnection
{
    private readonly \SensitiveParameterValue $secret;

    /**
     * @param  list<string>  $relays
     */
    private function __construct(
        public readonly string $walletPubkey,
        public readonly array $relays,
        public readonly string $clientPubkey,
        #[\SensitiveParameter] string $secret,
    ) {
        $this->secret = new \SensitiveParameterValue($secret);
    }

    public static function fromUri(#[\SensitiveParameter] mixed $uri): ?self
    {
        if (! is_string($uri) || trim($uri) === '') {
            return null;
        }

        $uri = trim($uri);
        $prefix = 'nostr+walletconnect://';

        if (! str_starts_with(strtolower($uri), $prefix)) {
            return null;
        }

        $rest = substr($uri, strlen($prefix));
        $question = strpos($rest, '?');

        if ($question === false) {
            return null;
        }

        $walletPubkey = strtolower(substr($rest, 0, $question));
        $query = substr($rest, $question + 1);
        $relays = [];
        $secret = null;

        // parse_str() would merge repeated `relay` parameters; NIP-47 allows several.
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $value = rawurldecode($value);

            if ($name === 'relay' && preg_match('#^wss?://[^\s]+$#', $value) === 1) {
                $relays[] = $value;
            } elseif ($name === 'secret') {
                $secret = NostrKeys::secretToHex($value);
            }
        }

        if (! NostrKeys::isHexPubkey($walletPubkey) || $relays === [] || $secret === null) {
            return null;
        }

        try {
            $clientPubkey = (new Key)->getPublicKey($secret);
        } catch (Throwable) {
            return null;
        }

        return new self($walletPubkey, array_values(array_unique($relays)), $clientPubkey, $secret);
    }

    /**
     * Run $work with the secret in hex. Nothing it returns may contain it.
     *
     * @template T
     *
     * @param  callable(string): T  $work
     * @return T
     */
    public function withSecret(callable $work): mixed
    {
        return $work($this->secret->getValue());
    }

    /**
     * @return array{wallet: string, relays: list<string>, client: string}
     */
    public function __debugInfo(): array
    {
        return ['wallet' => $this->walletPubkey, 'relays' => $this->relays, 'client' => $this->clientPubkey];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A wallet connection is never serialized.');
    }
}
