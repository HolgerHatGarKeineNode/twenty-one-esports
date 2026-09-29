<?php

namespace App\Support\Wallet;

use App\Support\Nostr\BoundedSocketStream;
use App\Support\Nostr\BoundedStreamFactory;
use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * NIP-47 over a relay, with the websocket client swentel/nostr-php depends on
 * (phrity), like App\Support\Nostr\RelayPublisher. The subscription goes out
 * before the request, so an answer the wallet sends at once is not missed.
 * A relay that refuses the request (`OK false`) or closes the subscription
 * ends the round trip early with null, like a timeout: the caller then does
 * not know whether the wallet acted, and treats it that way.
 *
 * Every relay passes {@see RelayGuard} first, and the socket is pinned to
 * the address it checked (security gate F1): a relay URL comes from a
 * connection string an organizer typed. A refused relay is never contacted.
 *
 * Every read on the socket stops at the round trip's own deadline and never
 * takes a frame beyond MAX_FRAME_BYTES or the connection beyond MAX_BYTES
 * ({@see BoundedSocketStream}; P47 audit follow-up): before, the deadline was
 * looked at only between frames, so a relay that announced a 1e9-byte frame
 * was a PHP fatal and one that dripped a frame held the worker 49 s. The
 * long wait for a wallet's answer is the deadline itself, unchanged.
 */
final class WebsocketNwcTransport implements NwcTransport
{
    /** Largest frame taken from a wallet relay, in bytes. */
    public const MAX_FRAME_BYTES = 65536;

    /** Bytes one connection may read in all (the upgrade answer and every frame). */
    public const MAX_BYTES = 1048576;

    public function __construct(private readonly RelayGuard $guard) {}

    public function roundTrip(string $relay, array $request, array $filter, float $timeout, callable $accept): ?array
    {
        return $this->exchange($relay, $filter, $timeout, $request, $accept);
    }

    public function fetch(string $relay, array $filter, float $timeout): ?array
    {
        return $this->exchange($relay, $filter, $timeout, null, null);
    }

    /**
     * Read until the relay ended the stored events of `$subscription`; false
     * when it closed the subscription or the deadline passed.
     */
    private function awaitEose(Client $client, string $subscription, float $deadline): bool
    {
        while (microtime(true) < $deadline) {
            $frame = $client->receive();
            $message = $frame instanceof Text ? json_decode($frame->getContent(), true) : null;

            if (is_array($message) && ($message[1] ?? null) === $subscription) {
                if (($message[0] ?? null) === 'EOSE') {
                    return true;
                }

                if (($message[0] ?? null) === 'CLOSED') {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>|null  $request
     * @param  (callable(array<string, mixed>): bool)|null  $accept
     * @return array<string, mixed>|null
     */
    private function exchange(string $relay, array $filter, float $timeout, ?array $request, ?callable $accept): ?array
    {
        $target = $this->guard->target($relay);

        if ($target === null) {
            return null;
        }

        $deadline = microtime(true) + $timeout;
        $subscription = 'nwc-'.bin2hex(random_bytes(6));
        $client = null;
        $newest = null;

        try {
            $client = new Client($relay);
            $client->setStreamFactory($target['ip'] !== null
                ? new PinnedStreamFactory($target['host'], $target['ip'], $deadline, self::MAX_BYTES, self::MAX_FRAME_BYTES)
                : new BoundedStreamFactory($deadline, self::MAX_BYTES, self::MAX_FRAME_BYTES));

            $client->setTimeout(max(1, (int) ceil($timeout)));
            $client->text((string) json_encode(['REQ', $subscription, $filter], JSON_UNESCAPED_SLASHES));

            if ($request !== null) {
                // The answer (23195) is ephemeral: a relay hands it only to a subscription that is
                // already registered. A relay may handle a connection's messages concurrently
                // (khatru does), so the request goes out only after the REQ's EOSE.
                if (! $this->awaitEose($client, $subscription, $deadline)) {
                    return null;
                }

                $client->text((string) json_encode(['EVENT', $request], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            while (microtime(true) < $deadline) {
                $frame = $client->receive();

                if (! $frame instanceof Text) {
                    continue;
                }

                $message = json_decode($frame->getContent(), true);

                if (! is_array($message)) {
                    continue;
                }

                $type = $message[0] ?? null;

                if ($type === 'EVENT' && ($message[1] ?? null) === $subscription && is_array($message[2] ?? null)) {
                    if ($request !== null) {
                        if ($accept !== null && $accept($message[2])) {
                            return $message[2];
                        }

                        continue;
                    }

                    if ($newest === null || (int) ($message[2]['created_at'] ?? 0) > (int) ($newest['created_at'] ?? 0)) {
                        $newest = $message[2];
                    }
                } elseif ($type === 'EOSE' && ($message[1] ?? null) === $subscription && $request === null) {
                    return $newest;
                } elseif ($type === 'OK' && $request !== null && ($message[1] ?? null) === ($request['id'] ?? null) && ($message[2] ?? null) !== true) {
                    return null;
                } elseif ($type === 'CLOSED' && ($message[1] ?? null) === $subscription) {
                    return $newest;
                }
            }

            return $newest;
        } catch (Throwable) {
            return $newest;
        } finally {
            try {
                $client?->text((string) json_encode(['CLOSE', $subscription]));
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }
    }
}
