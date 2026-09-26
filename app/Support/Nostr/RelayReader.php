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
    public const FILTERS_PER_REQ = 20;

    /**
     * @param  list<array<string, mixed>>  $filters  NIP-01 filters of one REQ; each needs `kinds`
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @param  array<string, true>  $known  ids the caller has already (checked and stored): skipped
     *                                      before the costly signature check
     * @param  int  $max  most events one fetch takes and verifies in total, over every relay and REQ
     *                    (about 0.1 s of signature checking each); the rest is dropped unread
     * @return list<SignedEvent> distinct by id, none of $known, each matching one of the filters
     */
    public function fetch(array $filters, ?array $relays = null, array $known = [], int $max = 5000): array
    {
        $events = [];

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            // Relays cap the filters of one REQ; one reporter per filter means many.
            foreach (array_chunk($filters, self::FILTERS_PER_REQ) as $chunk) {
                if (count($events) >= $max) {
                    break 2;
                }

                foreach ($this->read($relay, $chunk, $known + $events, $max - count($events)) as $event) {
                    $events[$event->id] ??= $event;
                }
            }
        }

        return array_values($events);
    }

    /**
     * Collect until EOSE (or the deadline, or $max events), then check: the
     * signature check (about 0.1 s each) runs after the connection closed, so
     * a flood of junk cannot use up the time the real events need, and an
     * event is kept only if it matches a filter the league sent (a relay may
     * ignore `authors` or `since`).
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, mixed>  $skip  ids not to return
     * @return list<SignedEvent>
     */
    private function read(string $relay, array $filters, array $skip, int $max): array
    {
        if (preg_match('#^wss?://#', $relay) !== 1 || $filters === []) {
            return [];
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $deadline = microtime(true) + $timeout;
        $subscription = 'read-'.bin2hex(random_bytes(4));
        $received = [];
        $client = null;

        try {
            $client = new Client($relay);
            $client->setTimeout($timeout);
            $client->text((string) json_encode(['REQ', $subscription, ...$filters], JSON_UNESCAPED_SLASHES));

            while (microtime(true) < $deadline && count($received) < $max) {
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

                if ($event !== null && ! isset($skip[$event->id]) && self::matchesAny($event, $filters)) {
                    $received[$event->id] = $event;
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

        return array_values(array_filter($received, fn (SignedEvent $event): bool => $event->hasValidSignature()));
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private static function matchesAny(SignedEvent $event, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (self::matches($event, $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * kinds, authors, since, until and single-letter tag filters (#d, #L, ...).
     *
     * @param  array<string, mixed>  $filter
     */
    private static function matches(SignedEvent $event, array $filter): bool
    {
        if (! in_array($event->kind, array_map(intval(...), (array) ($filter['kinds'] ?? [])), true)) {
            return false;
        }

        if (isset($filter['authors']) && ! in_array($event->pubkey, (array) $filter['authors'], true)) {
            return false;
        }

        if ((isset($filter['since']) && $event->createdAt < (int) $filter['since']) || (isset($filter['until']) && $event->createdAt > (int) $filter['until'])) {
            return false;
        }

        foreach ($filter as $key => $values) {
            if (preg_match('/^#([a-zA-Z])$/', $key, $letter) === 1) {
                $tagValues = array_map(fn (array $tag): ?string => $tag[0] ?? null, $event->tagsNamed($letter[1]));

                if (array_intersect((array) $values, $tagValues) === []) {
                    return false;
                }
            }
        }

        return true;
    }
}
