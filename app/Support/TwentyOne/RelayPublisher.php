<?php

namespace App\Support\TwentyOne;

use Closure;

/**
 * Sends one signed event to a list of relays and reports each relay's answer.
 *
 * A relay counts as accepted only when it answers `["OK", <id>, true, …]` for
 * exactly this event id before the deadline. Everything else (no connection,
 * a rejection, NOTICE/AUTH chatter until the deadline, a closed socket) is a
 * failure with a reason, so a silent relay can never look like a success.
 *
 * All relays are contacted at the same time over non-blocking sockets and
 * one stream_select() loop, under a single overall deadline: n silent relays
 * cost the timeout once, not n times. An optional abort callback ends the
 * whole publish early (the stream supervisor uses it on SIGTERM).
 * Only name resolution blocks; it happens once per relay before the loop.
 */
final class RelayPublisher
{
    /**
     * @param  array<string, mixed>  $sslOptions  extra `ssl` stream context options (tests: a local CA)
     */
    public function __construct(private array $sslOptions = []) {}

    /**
     * A relay list from config or a `--relays=a,b` option: trimmed, without
     * blanks and duplicates. URLs are checked later, per relay, by publish().
     *
     * @return list<string>
     */
    public static function relayUrls(mixed $relays): array
    {
        if (is_string($relays)) {
            $relays = explode(',', $relays);
        }

        if (! is_array($relays)) {
            return [];
        }

        $relays = array_map(fn (mixed $relay): string => is_string($relay) ? trim($relay) : '', $relays);

        return array_values(array_unique(array_filter($relays, fn (string $relay): bool => $relay !== '')));
    }

    /**
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}  $event
     * @param  list<string>  $relays
     * @param  (Closure(): bool)|null  $abort  checked every loop pass; true ends the publish
     * @return array<string, PublishResult> keyed by relay URL, in the order given
     */
    public function publish(array $event, array $relays, int|float $timeoutSeconds = 5, ?Closure $abort = null): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $payload = json_encode(['EVENT', $event], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $results = [];
        /** @var array<string, RelayConnection> $open */
        $open = [];

        foreach (array_unique($relays) as $relay) {
            $results[$relay] = null;

            if (! EventBuilder::isRelayUrl($relay)) {
                $results[$relay] = new PublishResult($relay, false, 'not a ws:// or wss:// URL');

                continue;
            }

            $connection = RelayConnection::open($relay, $payload, $this->sslOptions);

            if ($connection->failure !== null) {
                $results[$relay] = new PublishResult($relay, false, $connection->failure);
            } else {
                $open[$relay] = $connection;
            }
        }

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            $aborted = $abort !== null && $abort();

            if ($remaining <= 0 || $aborted) {
                foreach ($open as $relay => $connection) {
                    $results[$relay] = new PublishResult($relay, false, $aborted ? 'aborted' : $connection->timeoutReason());
                    $connection->close();
                }

                break;
            }

            $read = [];
            $write = [];

            foreach ($open as $connection) {
                if ($connection->wantsRead()) {
                    $read[] = $connection->socket;
                }

                if ($connection->wantsWrite()) {
                    $write[] = $connection->socket;
                }
            }

            $except = null;
            // Short slices so the abort callback and signal flags are seen quickly.
            $slice = min($remaining, 0.1);

            if (@stream_select($read, $write, $except, 0, (int) ($slice * 1_000_000)) === false) {
                // Interrupted by a signal: loop, re-check abort and deadline.
                continue;
            }

            foreach ($open as $relay => $connection) {
                $connection->advance(
                    in_array($connection->socket, $read, true),
                    in_array($connection->socket, $write, true),
                    $event['id'],
                );

                if ($connection->result !== null) {
                    $results[$relay] = new PublishResult($relay, $connection->result[0], $connection->result[1]);
                    $connection->close();
                    unset($open[$relay]);
                }
            }
        }

        /** @var array<string, PublishResult> $results */
        return $results;
    }
}
