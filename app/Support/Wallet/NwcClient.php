<?php

namespace App\Support\Wallet;

use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Throwable;

/**
 * NIP-47 requests over one connection: a kind `23194` request signed with
 * the connection's client key and encrypted to the wallet, the answer a kind
 * `23195` from the wallet whose `e` names the request.
 *
 * - Encryption: `nip44_v2` when the wallet's info event (`13194`) lists it
 *   in its `encryption` tag, else `nip04` (NIP-47: no tag means NIP-04).
 *   The info event is read once an hour.
 * - Every request carries an `expiration` (NIP-47 "Request expiration"): a
 *   wallet must not act on a request that reached it after its timeout, so
 *   a payment the league gave up on waiting for cannot happen later.
 * - An answer counts only if it is well formed, signed by the wallet, names
 *   the request, decrypts, and its `result_type` is the method asked for.
 *   Anything else is ignored, and no answer in time is NwcError TIMEOUT.
 */
final class NwcClient
{
    public const REQUEST = 23194;

    public const RESPONSE = 23195;

    public const INFO = 13194;

    public function __construct(private readonly NwcConnection $connection, private readonly NwcTransport $transport) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the `result`
     *
     * @throws NwcError
     */
    public function request(string $method, array $params, ?float $timeout = null): array
    {
        $timeout ??= (float) config('esports.wallet.nwc_timeout_seconds', 30);
        $scheme = $this->encryption();
        $relay = $this->connection->relays[0];
        $plaintext = (string) json_encode(['method' => $method, 'params' => (object) $params], JSON_UNESCAPED_SLASHES);
        $wallet = $this->connection->walletPubkey;

        $request = $this->connection->withSecret(function (#[\SensitiveParameter] string $secret) use ($scheme, $plaintext, $wallet, $timeout): array {
            $now = now()->getTimestamp();
            $tags = [['p', $wallet], ['expiration', (string) ($now + (int) ceil($timeout))]];

            if ($scheme === NwcCipher::NIP44) {
                $tags[] = ['encryption', NwcCipher::NIP44];
            }

            $event = (new Event)->setKind(self::REQUEST)->setTags($tags)->setCreatedAt($now)
                ->setContent(NwcCipher::encrypt($scheme, $plaintext, $secret, $wallet));

            try {
                (new Sign)->signEvent($event, $secret);
            } catch (Throwable) {
                throw new RuntimeException('Signing the NWC request failed.');
            }

            /** @var array<string, mixed> */
            return $event->toArray();
        });

        $response = null;
        $this->transport->roundTrip($relay, $request, [
            'kinds' => [self::RESPONSE],
            'authors' => [$wallet],
            '#e' => [$request['id']],
        ], $timeout, function (array $answer) use (&$response, $request, $scheme): bool {
            $response = $this->open($answer, (string) $request['id'], $scheme);

            return $response !== null;
        });

        if ($response === null) {
            throw new NwcError(NwcError::TIMEOUT);
        }

        if (is_array($response['error'] ?? null)) {
            throw new NwcError(is_string($response['error']['code'] ?? null) ? $response['error']['code'] : 'OTHER', is_string($response['error']['message'] ?? null) ? $response['error']['message'] : '');
        }

        if (($response['result_type'] ?? null) !== $method || ! is_array($response['result'] ?? null)) {
            throw new NwcError('OTHER', 'unexpected answer');
        }

        return $response['result'];
    }

    /**
     * The encryption the wallet announces in its info event (cached).
     */
    public function encryption(): string
    {
        $wallet = $this->connection->walletPubkey;

        return (string) Cache::remember('nwc-encryption:'.$wallet, 3600, function () use ($wallet): string {
            $info = SignedEvent::fromInput($this->transport->fetch($this->connection->relays[0], ['kinds' => [self::INFO], 'authors' => [$wallet], 'limit' => 1], 10.0));

            if ($info === null || $info->pubkey !== $wallet || $info->kind !== self::INFO || ! $info->hasValidSignature()) {
                return NwcCipher::NIP04;
            }

            $schemes = preg_split('/\s+/', (string) $info->tag('encryption')) ?: [];

            return in_array(NwcCipher::NIP44, $schemes, true) ? NwcCipher::NIP44 : NwcCipher::NIP04;
        });
    }

    /**
     * The decrypted answer to the request, or null when the event is not one.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>|null
     */
    private function open(array $answer, string $requestId, string $scheme): ?array
    {
        $event = SignedEvent::fromInput($answer);

        if ($event === null || $event->kind !== self::RESPONSE || $event->pubkey !== $this->connection->walletPubkey
            || $event->tag('e') !== $requestId || ! $event->hasValidSignature()) {
            return null;
        }

        $wallet = $this->connection->walletPubkey;

        try {
            $json = $this->connection->withSecret(fn (#[\SensitiveParameter] string $secret): string => NwcCipher::decrypt($scheme, $event->content, $secret, $wallet));
        } catch (RuntimeException) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
