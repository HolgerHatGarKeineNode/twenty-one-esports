<?php

namespace App\Support\Nostr;

use App\Models\NostrEvent;
use App\Models\RelayDelivery;
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
 */
final class RelayPublisher
{
    /**
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @return array<string, array{accepted: bool, message: string}>
     */
    public function publish(NostrEvent $event, ?array $relays = null): array
    {
        $results = [];

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            $results[$relay] = $this->send($relay, $event);

            RelayDelivery::query()->updateOrCreate(
                ['nostr_event_id' => $event->id, 'relay' => $relay],
                ['accepted' => $results[$relay]['accepted'], 'message' => mb_substr($results[$relay]['message'], 0, 500), 'attempted_at' => now()],
            );
        }

        return $results;
    }

    /**
     * @return array{accepted: bool, message: string}
     */
    private function send(string $relay, NostrEvent $event): array
    {
        if (preg_match('#^wss?://#', $relay) !== 1) {
            return ['accepted' => false, 'message' => 'error: not a websocket url'];
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $deadline = microtime(true) + $timeout;
        $client = null;

        try {
            $client = new Client($relay);
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
