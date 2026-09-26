<?php

namespace App\Support\Nostr;

use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Reads events from the configured relays (NIP-01 `REQ` until `EOSE`), for
 * the trust job (opponent lists `30000` and reports `1984`).
 *
 * Every event is checked like any input from outside: well-formed
 * (SignedEvent::fromInput), matching the filter's kinds, with a valid id and
 * signature. A relay that fails, times out or sends garbage contributes
 * nothing; the caller keeps what it already archived, so an outage never
 * turns into "nobody lists anybody". Uses the same websocket client as
 * {@see RelayPublisher}.
 */
class RelayReader
{
    /**
     * @param  array<string, mixed>  $filter  one NIP-01 filter; `kinds` is required
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @param  array<string, true>  $known  ids the caller has already (checked and stored): skipped
     *                                      before the costly signature check
     * @return list<SignedEvent> distinct by id, none of $known
     */
    public function fetch(array $filter, ?array $relays = null, array $known = []): array
    {
        $events = [];

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            foreach ($this->read($relay, $filter, $known + $events) as $event) {
                $events[$event->id] ??= $event;
            }
        }

        return array_values($events);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $skip  ids not to return
     * @return list<SignedEvent>
     */
    private function read(string $relay, array $filter, array $skip): array
    {
        if (preg_match('#^wss?://#', $relay) !== 1) {
            return [];
        }

        $kinds = array_map(intval(...), (array) ($filter['kinds'] ?? []));
        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $deadline = microtime(true) + $timeout;
        $subscription = 'read-'.bin2hex(random_bytes(4));
        $events = [];
        $client = null;

        try {
            $client = new Client($relay);
            $client->setTimeout($timeout);
            $client->text((string) json_encode(['REQ', $subscription, $filter], JSON_UNESCAPED_SLASHES));

            while (microtime(true) < $deadline) {
                $frame = $client->receive();

                if (! $frame instanceof Text) {
                    continue;
                }

                $message = json_decode($frame->getContent(), true);

                if (! is_array($message) || ($message[1] ?? null) !== $subscription) {
                    continue;
                }

                if (($message[0] ?? null) === 'EOSE' || ($message[0] ?? null) === 'CLOSED') {
                    break;
                }

                $event = ($message[0] ?? null) === 'EVENT' ? SignedEvent::fromInput($message[2] ?? null) : null;

                if ($event !== null && ! isset($skip[$event->id]) && in_array($event->kind, $kinds, true) && $event->hasValidSignature()) {
                    $events[] = $event;
                    $skip[$event->id] = true;
                }
            }

            $client->text((string) json_encode(['CLOSE', $subscription]));
        } catch (Throwable $exception) {
            Log::warning('Relay read failed', ['relay' => $relay, 'error' => $exception->getMessage()]);
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }

        return $events;
    }
}
