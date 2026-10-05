<?php

namespace App\Support\Moderation;

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\PubkeyModeration;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LeagueKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * The league's public mute list (user, 2026-10-05: the site's mutes and bans
 * go out as a NIP-51 mute list, signed by the league key, so other Nostr
 * clients can honour them): kind `10000`, one public `p` tag per key that is
 * muted or banned on this site, deduplicated and sorted, empty `content` (no
 * private items, no NIP-44 part). It never says why a key is listed, nor
 * whether it was muted or banned: that stays with the admins
 * ({@see SiteModeration}). Admins, board members and the league key itself
 * are never listed, even if a row names one.
 *
 * Kind 10000 is replaceable (NIP-01), so every version is the full list and
 * replaces the one before; an empty list is published too, so lifting the
 * last mute reaches the relays. Written by its one coalescing job
 * (App\Jobs\PublishLeagueMuteList) after every committed change, serialized
 * by a lock, each version signed at the current second and after the
 * previous one, so a newer list never loses to an older one on a relay.
 * A run whose keys equal the newest version's signs nothing.
 *
 * Stored in nostr_events and sent to the league relays (`esports.relays`)
 * through LeagueKey::publish and PublishNostrEvent; a relay that refuses or
 * times out is recorded per relay and never fails the run, and
 * `nostr:republish` sends the current version again to relays that have not
 * taken it. Fail closed: without the league key nothing is signed.
 *
 * Off until `esports.league.mute_list` is on (ESPORTS_PUBLISH_MUTE_LIST):
 * the league key is also the profile a person may use in a Nostr client,
 * and a client's own mute list of that key, its private items included,
 * is replaced by the first version signed here (measured 2026-10-05: the
 * production key held an Amethyst mute list with private items only).
 */
final class LeagueMuteList
{
    public const KIND = 10000;

    private const LOCK = 'league-mute-list-writer';

    /**
     * The keys the list names: every active mute and ban, once each, sorted,
     * without the league key, admins and board members.
     *
     * @return list<string>
     */
    public static function entries(?string $leaguePubkey = null): array
    {
        $keys = array_values(array_unique(array_map(
            fn (mixed $pubkey): string => is_string($pubkey) ? $pubkey : '',
            PubkeyModeration::query()->active()->distinct()->pluck('pubkey')->all(),
        )));
        // Sorted here, not by the database: the comparison with listed() must not hang on a collation.
        sort($keys, SORT_STRING);
        $admins = $keys === [] ? [] : Admin::query()->whereIn('pubkey', $keys)->pluck('pubkey')->all();

        return array_values(array_filter(
            $keys,
            fn (string $pubkey): bool => NostrKeys::isHexPubkey($pubkey) && $pubkey !== $leaguePubkey && ! Board::contains($pubkey) && ! in_array($pubkey, $admins, true),
        ));
    }

    /**
     * Sign, store and queue a new version when the keys changed. Null when
     * nothing was signed (switched off, no league key, or the newest version
     * lists the same keys).
     */
    public function publish(): ?NostrEvent
    {
        $league = config('esports.league.mute_list') === true ? LeagueKey::fromConfig() : null;

        if ($league === null) {
            return null;
        }

        return Cache::lock(self::LOCK, 30)->block(20, function () use ($league): ?NostrEvent {
            $previous = self::newest($league->pubkey());
            $entries = self::entries($league->pubkey());

            if ($previous !== null && self::listed($previous) === $entries) {
                return null;
            }

            $last = $previous === null ? 0 : $previous->signed_at;

            // Never dated ahead of the clock: two changes in one second wait for the next one.
            if ($last >= now()->getTimestamp()) {
                Sleep::until(CarbonImmutable::createFromTimestamp($last + 1));
            }

            $tags = array_map(fn (string $pubkey): array => ['p', $pubkey], $entries);

            return DB::transaction(fn (): NostrEvent => $league->publish(self::KIND, $tags, '', max(now()->getTimestamp(), $last + 1)));
        });
    }

    /** The newest stored version of the league's mute list (highest `created_at`, ties to the lower id, NIP-01). */
    public static function newest(string $leaguePubkey): ?NostrEvent
    {
        return NostrEvent::query()->where('pubkey', $leaguePubkey)->where('kind', self::KIND)
            ->orderByDesc('signed_at')->orderBy('event_id')->first();
    }

    /**
     * The sorted, distinct keys of a version's public `p` tags.
     *
     * @return list<string>
     */
    public static function listed(NostrEvent $event): array
    {
        $keys = [];

        foreach ((array) ($event->payload()['tags'] ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null)) {
                $keys[$tag[1]] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
