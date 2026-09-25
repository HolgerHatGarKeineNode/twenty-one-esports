<?php

namespace App\Support\TwentyOne;

use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Sends one signed event to a list of relays and reports each relay's answer.
 *
 * A relay counts as accepted only when it answers `["OK", <id>, true, …]` for
 * exactly this event id before the deadline. Everything else (no connection,
 * a rejection, NOTICE/AUTH chatter until the deadline, a closed socket) is a
 * failure with a reason, so a silent relay can never look like a success.
 * Relays are contacted one after another, each with its own deadline.
 */
final class RelayPublisher
{
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
     * @return array<string, PublishResult> keyed by relay URL
     */
    public function publish(array $event, array $relays, int|float $timeoutSeconds = 5): array
    {
        $results = [];

        foreach (array_unique($relays) as $relay) {
            $results[$relay] = $this->publishTo($relay, $event, $timeoutSeconds);
        }

        return $results;
    }

    /**
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}  $event
     */
    private function publishTo(string $relay, array $event, int|float $timeoutSeconds): PublishResult
    {
        if (! EventBuilder::isRelayUrl($relay)) {
            return new PublishResult($relay, false, 'not a ws:// or wss:// URL');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $client = null;

        try {
            $client = (new Client($relay))->setTimeout($timeoutSeconds);
            $client->connect();
            $client->text(json_encode(['EVENT', $event], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            while (($remaining = $deadline - microtime(true)) > 0) {
                $client->setTimeout($remaining);
                $message = $client->receive();

                if (! $message instanceof Text) {
                    continue;
                }

                $answer = json_decode($message->getContent(), true);

                if (is_array($answer) && ($answer[0] ?? null) === 'OK' && ($answer[1] ?? null) === $event['id']) {
                    $reason = is_string($answer[3] ?? null) ? $answer[3] : '';

                    return new PublishResult($relay, ($answer[2] ?? null) === true, $reason);
                }
            }

            return new PublishResult($relay, false, 'no OK before the timeout');
        } catch (Throwable $e) {
            return new PublishResult($relay, false, $e->getMessage() !== '' ? $e->getMessage() : $e::class);
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // The result is already decided; a failing close changes nothing.
            }
        }
    }
}
