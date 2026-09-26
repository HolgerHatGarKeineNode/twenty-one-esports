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
 * `$perAuthor` events of each author, newest first over every relay, so
 * nobody's events can crowd out anybody else's (security re-check round 3,
 * Q5) and the relay order decides nothing (round 4).
 *
 * Filters go out in REQs of at most the relay's NIP-11
 * `limitation.max_filters`, never more than 10 (rnostr's limit, the default
 * when the relay does not say), one subscription after the other on one
 * connection per relay and fetch. A relay that advertises fewer than 5 is
 * not read at all (round 4: it would need too many REQs). A relay that fails,
 * times out, refuses a REQ with `CLOSED` or uses up its time budget for the
 * fetch is a failed read: logged with its reason, its events of that fetch
 * dropped. The caller keeps what it already archived, so an outage or a
 * refusal never turns into "nobody lists anybody" (stale but valid, not "no
 * data"). Uses the same websocket client as {@see RelayPublisher}.
 */
class RelayReader
{
    /** Filters per REQ when the relay does not say, and the most ever sent (rnostr allows 10). */
    public const MAX_FILTERS = 10;

    /** Fewer filters per REQ than this, and the relay is not read (round 4). */
    public const MIN_FILTERS = 5;

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
        $collected = [];

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            // A failed read (null): nothing of this relay counts this time.
            foreach ($this->read($relay, $filters, $known + $collected, $perAuthor) ?? [] as $event) {
                $collected[$event->id] = $event;
            }
        }

        // Newest first over every relay, then at most $perAuthor per author.
        usort($collected, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);
        $events = [];
        $kept = [];

        foreach ($collected as $event) {
            if (($kept[$event->pubkey] ?? 0) < $perAuthor) {
                $events[] = $event;
                $kept[$event->pubkey] = ($kept[$event->pubkey] ?? 0) + 1;
            }
        }

        return $events;
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
     * All filters of one fetch on one connection, a subscription per batch,
     * each collected until EOSE, then checked: the signature check (about
     * 0.1 s each) runs after the connection closed, so a flood of junk cannot
     * use up the time the real events need. Per author at most a few times
     * $perAuthor events are collected; the caller keeps $perAuthor. Each REQ
     * gets `relay_timeout_seconds`, the whole read `relay_fetch_budget_seconds`.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, mixed>  $skip  ids not to return
     * @return list<SignedEvent>|null null for a failed read (unsupported, error, timeout, CLOSED, budget used up)
     */
    private function read(string $relay, array $filters, array $skip, int $perAuthor): ?array
    {
        if (preg_match('#^wss?://#', $relay) !== 1 || $filters === []) {
            return [];
        }

        $batch = $this->maxFiltersOf($relay);

        if ($batch < self::MIN_FILTERS) {
            Log::warning('Relay unsupported', ['relay' => $relay, 'reason' => 'NIP-11 max_filters '.$batch.' is below '.self::MIN_FILTERS]);

            return null;
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $budget = microtime(true) + (float) config('esports.relay_fetch_budget_seconds', 15);
        $received = [];
        $perPubkey = [];
        $client = null;

        try {
            $client = new Client($relay);

            foreach (array_chunk($filters, $batch) as $chunk) {
                $deadline = min(microtime(true) + $timeout, $budget);
                $subscription = 'read-'.bin2hex(random_bytes(4));
                $complete = false;
                $client->setTimeout(max(0.05, $deadline - microtime(true)));
                $client->text((string) json_encode(['REQ', $subscription, ...$chunk], JSON_UNESCAPED_SLASHES));

                while (($left = $deadline - microtime(true)) > 0) {
                    $client->setTimeout(max(0.05, $left));
                    $frame = $client->receive();

                    if (! $frame instanceof Text) {
                        continue;
                    }

                    $message = json_decode($frame->getContent(), true);

                    if (! is_array($message) || ($message[1] ?? null) !== $subscription) {
                        continue;
                    }

                    if (($message[0] ?? null) === 'CLOSED') {
                        Log::warning('Relay refused a read', ['relay' => $relay, 'reason' => (string) ($message[2] ?? ''), 'filters' => count($chunk)]);

                        return null;
                    }

                    if (($message[0] ?? null) === 'EOSE') {
                        $complete = true;

                        break;
                    }

                    $event = ($message[0] ?? null) === 'EVENT' ? SignedEvent::fromInput($message[2] ?? null) : null;

                    if ($event !== null && ! isset($skip[$event->id]) && ($perPubkey[$event->pubkey] ?? 0) < 4 * $perAuthor && self::matchesAny($event, $chunk)) {
                        $received[$event->id] = $event;
                        $perPubkey[$event->pubkey] = ($perPubkey[$event->pubkey] ?? 0) + 1;
                    }
                }

                if (! $complete) {
                    return self::timedOut($relay, $deadline >= $budget, count($chunk));
                }

                $client->text((string) json_encode(['CLOSE', $subscription]));
            }
        } catch (Throwable $exception) {
            if (microtime(true) >= $budget) {
                return self::timedOut($relay, true, count($filters));
            }

            Log::warning('Relay read failed', ['relay' => $relay, 'error' => $exception->getMessage()]);

            return null;
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }

        return array_values(array_filter($received, fn (SignedEvent $event): bool => $event->hasValidSignature()));
    }

    /** @return null a failed read */
    private static function timedOut(string $relay, bool $overBudget, int $filters): null
    {
        Log::warning($overBudget ? 'Relay read over its time budget' : 'Relay read timed out before EOSE', ['relay' => $relay, 'filters' => $filters]);

        return null;
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
