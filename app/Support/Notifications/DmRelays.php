<?php

namespace App\Support\Notifications;

use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Looks up where a player receives DMs (NIP-17 `10050`) and reads (NIP-65
 * `10002`), on the league's configured profile and chat relays: the
 * discovery relays the browser already asks for profiles, plus the relays
 * the notification DMs have always gone to.
 *
 * Only a lookup that at least one relay answered to EOSE is remembered, for
 * CACHE_MINUTES: a failed lookup is not "no list" ({@see DmRoute::$known}).
 * Relay URLs come from a signed event of the player and are untrusted: at
 * most MAX_RELAYS of each list, `ws(s)://` only; where the server may really
 * connect is decided when publishing ({@see RelayPublisher::publishGuarded()}).
 */
final class DmRelays
{
    public const CACHE_MINUTES = 30;

    public const MAX_RELAYS = 5;

    public function __construct(private readonly RelayReader $reader) {}

    /**
     * @return list<string>
     */
    public static function lookupRelays(): array
    {
        return array_values(array_unique([
            ...(array) config('esports.profile_relays', []),
            ...(array) config('esports.chat.relays', []),
        ]));
    }

    /**
     * `$fresh` skips the cache (the settings page's test DM: a player who just
     * published a `10050` wants it used).
     */
    public function for(string $pubkey, bool $fresh = false): DmRoute
    {
        if (! NostrKeys::isHexPubkey($pubkey)) {
            return DmRoute::unknown();
        }

        $key = 'dm-relays:'.$pubkey;

        if (! $fresh && is_array($cached = Cache::get($key))) {
            return new DmRoute(true, $cached['dm'] ?? [], $cached['inbox'] ?? []);
        }

        $relays = self::lookupRelays();

        if ($relays === []) {
            return DmRoute::unknown();
        }

        $read = $this->reader->fetchCounted([
            ['kinds' => [10050], 'authors' => [$pubkey], 'limit' => 1],
            ['kinds' => [10002], 'authors' => [$pubkey], 'limit' => 1],
        ], $relays, perAuthor: 4);

        if ($read['answered'] === 0) {
            return DmRoute::unknown();
        }

        $route = new DmRoute(
            true,
            self::urls(self::newest($read['events'], $pubkey, 10050), fn (array $tag): bool => ($tag[0] ?? null) === 'relay'),
            self::urls(self::newest($read['events'], $pubkey, 10002), fn (array $tag): bool => ($tag[0] ?? null) === 'r' && in_array($tag[2] ?? 'read', ['read'], true)),
        );

        Cache::put($key, ['dm' => $route->dmRelays, 'inbox' => $route->inboxRelays], now()->addMinutes(self::CACHE_MINUTES));

        return $route;
    }

    /**
     * NIP-01: of a replaceable kind the highest created_at wins, a tie to the lowest id.
     *
     * @param  list<SignedEvent>  $events
     */
    private static function newest(array $events, string $pubkey, int $kind): ?SignedEvent
    {
        $mine = array_values(array_filter($events, fn (SignedEvent $event): bool => $event->pubkey === $pubkey && $event->kind === $kind));
        usort($mine, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return $mine[0] ?? null;
    }

    /**
     * @param  callable(list<string>): bool  $wanted
     * @return list<string>
     */
    private static function urls(?SignedEvent $event, callable $wanted): array
    {
        $urls = [];

        foreach ($event === null ? [] : $event->tags as $tag) {
            $url = is_string($tag[1] ?? null) ? rtrim(trim($tag[1]), '/') : '';

            if ($wanted($tag) && preg_match('#^wss?://[^\s/]+#i', $url) === 1 && strlen($url) <= 200) {
                $urls[$url] = $url;
            }
        }

        return array_slice(array_values($urls), 0, self::MAX_RELAYS);
    }
}
