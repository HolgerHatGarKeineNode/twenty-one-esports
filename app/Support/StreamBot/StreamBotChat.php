<?php

namespace App\Support\StreamBot;

use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;

/**
 * Whether a human wrote in the stream chat since the bot's last post: one
 * REQ per relay of `esports.stream_bot.chat_relays` for kind 1311 with the
 * stream's `a` tag since then, capped by `limit`. RelayReader checks id,
 * signature and filter of every event; the bot's own messages do not count.
 *
 * Fail closed: no chat relay, a failed read or nothing found all answer
 * "nobody", so the bot waits for `alone_minutes` instead.
 */
class StreamBotChat
{
    /** Chat messages asked for per relay; one human is all the bot needs to see. */
    public const LIMIT = 20;

    public function __construct(private RelayReader $reader) {}

    /**
     * @return list<string> the pubkeys (not the bot's) that wrote since `$since`
     */
    public function humansSince(StreamCoordinates $stream, string $botPubkey, int $since): array
    {
        $relays = array_values(array_filter((array) config('esports.stream_bot.chat_relays', []), 'is_string'));

        if ($relays === []) {
            return [];
        }

        $events = $this->reader->fetch([[
            'kinds' => [1311],
            '#a' => [$stream->address()],
            'since' => $since,
            'limit' => self::LIMIT,
        ]], $relays, [], self::LIMIT);

        $humans = array_map(fn (SignedEvent $event): string => $event->pubkey, array_filter(
            $events,
            fn (SignedEvent $event): bool => $event->kind === 1311 && $event->pubkey !== $botPubkey && $event->createdAt >= $since,
        ));

        return array_values(array_unique($humans));
    }
}
