<?php

namespace App\Support\Nostr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Reads events from the configured relays (NIP-01 `REQ` until `EOSE`), for
 * the trust job (opponent lists `30000` and reports `1984`).
 *
 * Every event is checked like any input from outside: well-formed
 * (SignedEvent::fromInput), matching a filter the league sent (a relay may
 * ignore `authors` or `since`), with a valid id and signature. Callers ask
 * with one filter per author and a limit, and the reader keeps at most
 * `$perAuthor` events of each author (newest first), so nobody's events can
 * crowd out anybody else's (security re-check round 3, Q5).
 *
 * Filters go out in REQs of at most the relay's NIP-11
 * `limitation.max_filters`, never more than 10 (rnostr's limit, the default
 * when the relay does not say). A relay that fails, times out or refuses a
 * REQ with `CLOSED` is a failed read: logged with its reason, its events of
 * that fetch dropped and its remaining REQs skipped. The caller keeps what it
 * already archived, so an outage or a refusal never turns into "nobody lists
 * anybody" (stale but valid, not "no data"). Uses the same websocket client
 * as {@see RelayPublisher}.
 */
class RelayReader
{
    /** Filters per REQ when the relay does not say, and the most ever sent (rnostr allows 10). */
    public const MAX_FILTERS = 10;

    /** @var array<string, int<1, 10>> relay => filters per REQ, from its NIP-11 document */
    private array $maxFilters = [];

    /**
     * @param  list<array<string, mixed>>  $filters  NIP-01 filters; each needs `kinds`
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @param  array<string, true>  $known  ids the caller has already (checked and stored): skipped
     *                                      before the costly signature check
     * @param  int  $perAuthor  most events kept per author and fetch, newest first
     * @return list<SignedEvent> distinct by id, none of $known, each matching one of the filters
     */
    public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
    {
        $events = [];
        $kept = [];

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            $fromRelay = [];

            foreach (array_chunk($filters, $this->maxFiltersOf($relay)) as $chunk) {
                $read = $this->read($relay, $chunk, $known + $events, $perAuthor);

                if ($read === null) {
                    $fromRelay = []; // a failed read: nothing of this relay counts this time

                    break;
                }

                array_push($fromRelay, ...$read);
            }

            // Newest first, then at most $perAuthor per author over every relay.
            usort($fromRelay, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

            foreach ($fromRelay as $event) {
                if (! isset($events[$event->id]) && ($kept[$event->pubkey] ?? 0) < $perAuthor) {
                    $events[$event->id] = $event;
                    $kept[$event->pubkey] = ($kept[$event->pubkey] ?? 0) + 1;
                }
            }
        }

        return array_values($events);
    }

    /**
     * NIP-11 `limitation.max_filters` of the relay, at most MAX_FILTERS, and
     * MAX_FILTERS when the document is missing or says nothing.
     *
     * @return int<1, 10>
     */
    private function maxFiltersOf(string $relay): int
    {
        if (isset($this->maxFilters[$relay])) {
            return $this->maxFilters[$relay];
        }

        $limit = self::MAX_FILTERS;

        try {
            $response = Http::withHeaders(['Accept' => 'application/nostr+json'])->connectTimeout(2)->timeout(2)
                ->get((string) preg_replace('#^ws(s?)://#', 'http$1://', $relay));
            $advertised = $response->successful() ? $response->json('limitation.max_filters') : null;

            if (is_int($advertised) && $advertised > 0) {
                $limit = min($advertised, self::MAX_FILTERS);
            }
        } catch (Throwable) {
            // no NIP-11 document: the default
        }

        return $this->maxFilters[$relay] = $limit;
    }

    /**
     * Collect until EOSE (or the deadline), then check: the signature check
     * (about 0.1 s each) runs after the connection closed, so a flood of junk
     * cannot use up the time the real events need. Per author at most a few
     * times $perAuthor events are collected; the caller keeps $perAuthor.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, mixed>  $skip  ids not to return
     * @return list<SignedEvent>|null null for a failed read (error, timeout before EOSE, CLOSED)
     */
    private function read(string $relay, array $filters, array $skip, int $perAuthor): ?array
    {
        if (preg_match('#^wss?://#', $relay) !== 1 || $filters === []) {
            return [];
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $deadline = microtime(true) + $timeout;
        $subscription = 'read-'.bin2hex(random_bytes(4));
        $received = [];
        $perPubkey = [];
        $complete = false;
        $client = null;

        try {
            $client = new Client($relay);
            $client->setTimeout($timeout);
            $client->text((string) json_encode(['REQ', $subscription, ...$filters], JSON_UNESCAPED_SLASHES));

            while (microtime(true) < $deadline) {
                $frame = $client->receive();

                if (! $frame instanceof Text) {
                    continue;
                }

                $message = json_decode($frame->getContent(), true);

                if (! is_array($message) || ($message[1] ?? null) !== $subscription) {
                    continue;
                }

                if (($message[0] ?? null) === 'CLOSED') {
                    Log::warning('Relay refused a read', ['relay' => $relay, 'reason' => (string) ($message[2] ?? ''), 'filters' => count($filters)]);

                    return null;
                }

                if (($message[0] ?? null) === 'EOSE') {
                    $complete = true;

                    break;
                }

                $event = ($message[0] ?? null) === 'EVENT' ? SignedEvent::fromInput($message[2] ?? null) : null;

                if ($event !== null && ! isset($skip[$event->id]) && ($perPubkey[$event->pubkey] ?? 0) < 4 * $perAuthor && self::matchesAny($event, $filters)) {
                    $received[$event->id] = $event;
                    $perPubkey[$event->pubkey] = ($perPubkey[$event->pubkey] ?? 0) + 1;
                }
            }

            $client->text((string) json_encode(['CLOSE', $subscription]));
        } catch (Throwable $exception) {
            Log::warning('Relay read failed', ['relay' => $relay, 'error' => $exception->getMessage()]);

            return null;
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }

        if (! $complete) {
            Log::warning('Relay read timed out before EOSE', ['relay' => $relay, 'filters' => count($filters)]);

            return null;
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
