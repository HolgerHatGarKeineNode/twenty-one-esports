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
 * Only a complete lookup (every relay answered to EOSE within LOOKUP_SECONDS)
 * is remembered, for CACHE_MINUTES, and only it can say "no list"
 * ({@see DmRoute::$known}): a relay that did not answer may hold the newest
 * 10050 (audit F3). A 10050 found in a partial answer is still used.
 * Relay URLs come from a signed event of the player and are untrusted: at
 * most MAX_RELAYS of each list, `ws(s)://` only; where the server may really
 * connect is decided when publishing ({@see RelayPublisher::publishGuarded()}).
 */
final class DmRelays
{
    public const CACHE_MINUTES = 30;

    public const MAX_RELAYS = 5;

    /** The whole lookup's time; relays not read by then count as not answered. */
    public const LOOKUP_SECONDS = 12.0;

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
            return new DmRoute(true, $cached['dm'] ?? [], $cached['inbox'] ?? [], (bool) ($cached['named'] ?? false));
        }

        $relays = self::lookupRelays();

        if ($relays === []) {
            return DmRoute::unknown();
        }

        $read = $this->reader->fetchCounted([
            ['kinds' => [10050], 'authors' => [$pubkey], 'limit' => 1],
            ['kinds' => [10002], 'authors' => [$pubkey], 'limit' => 1],
        ], $relays, perAuthor: 4, until: microtime(true) + self::LOOKUP_SECONDS);

        // Complete: every lookup relay answered to EOSE. Only then is a missing 10050 a fact (audit F3).
        $complete = $read['answered'] === count($relays);
        $dmList = self::newest($read['events'], $pubkey, 10050);
        $route = new DmRoute(
            $complete,
            self::urls($dmList, fn (array $tag): bool => ($tag[0] ?? null) === 'relay'),
            self::urls(self::newest($read['events'], $pubkey, 10002), fn (array $tag): bool => ($tag[0] ?? null) === 'r' && in_array($tag[2] ?? 'read', ['read'], true)),
            self::namesRelays($dmList),
        );

        // A partial answer is never remembered: the next DM asks again.
        if (! $complete) {
            return $route;
        }

        Cache::put($key, ['dm' => $route->dmRelays, 'inbox' => $route->inboxRelays, 'named' => $route->namesDmRelays], now()->addMinutes(self::CACHE_MINUTES));

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
     * Whether a `10050` names any relay at all, usable here or not
     * ({@see DmRoute::$namesDmRelays}). An empty list names no inbox.
     */
    private static function namesRelays(?SignedEvent $list): bool
    {
        foreach ($list === null ? [] : $list->tags as $tag) {
            if (($tag[0] ?? null) === 'relay' && is_string($tag[1] ?? null) && trim($tag[1]) !== '') {
                return true;
            }
        }

        return false;
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
