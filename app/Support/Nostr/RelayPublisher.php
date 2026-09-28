<?php

namespace App\Support\Nostr;

use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Support\Wallet\PinnedStreamFactory;
use App\Support\Wallet\RelayGuard;
use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Publishes a stored signed event to the configured relays and records each
 * relay's answer (NIP-01 `["OK", <id>, <accepted>, <message>]`).
 *
 * A relay counts as accepting only on `OK true`. `true` with a `duplicate:`
 * prefix is still an accept (the relay has it); anything else, a timeout or
 * a connection error is recorded as not accepted, with the reason. A relay
 * failing never throws: the league's own database is the record, relays are
 * the public copy.
 *
 * Uses the websocket client that swentel/nostr-php depends on (phrity); the
 * library's own Relay::send() reads exactly one frame and would take an
 * AUTH or NOTICE frame for the answer.
 *
 * A relay gets relay_timeout_seconds in all, and MAX_BYTES of answer in all
 * (an OK frame is well under 1 KiB; the budget leaves room for an AUTH or
 * NOTICE first): past either the connection is dropped as a failed delivery,
 * however the relay drips or what it announces (BoundedSocketStream). A
 * player may name the relays of a DM, so this is what keeps a hostile one
 * from holding or killing the queue worker.
 */
final class RelayPublisher
{
    public const MAX_BYTES = 65536;

    /**
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @return array<string, array{accepted: bool, message: string}>
     */
    public function publish(NostrEvent $event, ?array $relays = null): array
    {
        return $this->deliver($event, $relays ?? config('esports.relays', []), []);
    }

    /**
     * publish() to relays a player named (a DM relay list `10050`, a NIP-65
     * inbox): untrusted input that makes the server connect, so each goes
     * through {@see RelayGuard} (`wss://` on 443 of a public DNS name) and the
     * socket is pinned to the checked address. A relay the guard refuses is
     * recorded as not accepted and never contacted. `$trusted` are the
     * league's own configured relays, reached as they are.
     *
     * @param  list<string>  $untrusted
     * @param  list<string>  $trusted
     * @return array<string, array{accepted: bool, message: string}>
     */
    public function publishGuarded(NostrEvent $event, array $untrusted, array $trusted = []): array
    {
        $guard = app(RelayGuard::class);
        $pins = [];

        foreach (array_diff($untrusted, $trusted) as $relay) {
            $pins[$relay] = $guard->target($relay) ?? false;
        }

        return $this->deliver($event, array_values(array_unique([...$trusted, ...$untrusted])), $pins);
    }

    /**
     * @param  list<string>  $relays
     * @param  array<string, array{host: string, ip: string|null}|false>  $pins  false: refused by the guard
     * @return array<string, array{accepted: bool, message: string}>
     */
    private function deliver(NostrEvent $event, array $relays, array $pins): array
    {
        $results = [];

        foreach ($relays as $relay) {
            $results[$relay] = ($pins[$relay] ?? null) === false
                ? ['accepted' => false, 'message' => 'error: relay not allowed']
                : $this->send($relay, $event, $pins[$relay] ?? null);

            RelayDelivery::query()->updateOrCreate(
                ['nostr_event_id' => $event->id, 'relay' => $relay],
                ['accepted' => $results[$relay]['accepted'], 'message' => mb_substr($results[$relay]['message'], 0, 500), 'attempted_at' => now()],
            );
        }

        return $results;
    }

    /**
     * @param  array{host: string, ip: string|null}|null  $pin  the address RelayGuard checked
     * @return array{accepted: bool, message: string}
     */
    private function send(string $relay, NostrEvent $event, ?array $pin = null): array
    {
        if (preg_match('#^wss?://#', $relay) !== 1) {
            return ['accepted' => false, 'message' => 'error: not a websocket url'];
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $deadline = microtime(true) + $timeout;
        $client = null;

        try {
            $client = new Client($relay);

            // Every read stops at the deadline and the byte budget, inside a frame too (P45 audit F1).
            $client->setStreamFactory($pin !== null && $pin['ip'] !== null
                ? new PinnedStreamFactory($pin['host'], $pin['ip'], $deadline, self::MAX_BYTES)
                : new BoundedStreamFactory($deadline, self::MAX_BYTES));

            $client->setTimeout($timeout);
            $client->text('["EVENT",'.$event->raw.']');

            while (microtime(true) < $deadline) {
                $frame = $client->receive();

                if (! $frame instanceof Text) {
                    continue;
                }

                $message = json_decode($frame->getContent(), true);

                if (is_array($message) && ($message[0] ?? null) === 'OK' && ($message[1] ?? null) === $event->event_id) {
                    return ['accepted' => ($message[2] ?? false) === true, 'message' => (string) ($message[3] ?? '')];
                }
            }

            return ['accepted' => false, 'message' => 'error: no OK before timeout'];
        } catch (Throwable $exception) {
            return ['accepted' => false, 'message' => 'error: '.$exception->getMessage()];
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }
    }
}
