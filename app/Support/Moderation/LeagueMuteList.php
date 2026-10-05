<?php

namespace App\Support\Moderation;

use App\Jobs\PublishNostrEvent;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\PubkeyModeration;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The league's public mute list (user, 2026-10-05: the site's mutes and bans
 * go out as a NIP-51 mute list, signed by the league key, so other Nostr
 * clients can honour them): kind `10000` with one public `p` tag per key
 * that is muted or banned on this site. It never says why a key is listed,
 * nor whether it was muted or banned: that stays with the admins
 * ({@see SiteModeration}). Admins, board members and the league key itself
 * are never listed by the site, even if a row names one.
 *
 * The league key is also a profile people use in Nostr clients, and a
 * client may keep its own mute list for it (measured 2026-10-05: production
 * held an Amethyst list with private items only). Kind 10000 is replaceable
 * (NIP-01): one list per key. So every version carries the newest list over
 * (user, 2026-10-05, option 2): before signing, the newest kind 10000 of the
 * league key is read from the league relays (to EOSE, signatures checked,
 * newest `created_at` over all relays and the stored copy, ties to the lower
 * id). Its `content` (the encrypted private part) is copied unchanged and
 * never decrypted; every tag that is not one of the site's own is kept as
 * it is (foreign public `p`, `t`, `word`, `e`, `client`, ...), exact
 * duplicates once. Then the site's `p` tags follow, sorted.
 *
 * The site's own keys are the keys it manages: the active moderations and
 * the keys the site added to its previous version, recorded per version in
 * `league_mute_list_versions` (the event alone cannot tell them from a
 * client's carried-over `p`). A lift so removes what the site added and
 * never a public `p` a client wrote for another key; a key a client listed
 * and the site moderated as well leaves the list with the site's lift.
 *
 * Fail closed: when no relay answers the read, nothing is signed
 * ({@see MuteListUnreadable}; the job tries again, the hourly
 * `esports:mute-list` run reconciles), so the private part is never at risk.
 * Each new version is dated after the newest list seen (relays and stored)
 * and, unless that one lies in the future, not ahead of the clock. A run
 * whose list equals the newest relay version (tags and content) signs
 * nothing; when the site's own newest version is the list but the relays
 * do not have it, that version is queued again instead of signing a new
 * one. So a client edit that dropped the site's keys is repaired by the
 * next run.
 *
 * Stored in nostr_events and sent to the league relays (`esports.relays`)
 * through LeagueKey::publish and PublishNostrEvent; a relay that refuses or
 * times out is recorded per relay and never fails the run. Writes run under
 * a lock, one at a time. Without the league key, with no relay configured,
 * or with `esports.league.mute_list` off (ESPORTS_PUBLISH_MUTE_LIST=false,
 * the kill switch) nothing is read or signed.
 */
final class LeagueMuteList
{
    public const KIND = 10000;

    /** Seconds the writer waits at most for a newest list dated in this very second or the next. */
    private const MAX_WAIT = 2;

    private const LOCK = 'league-mute-list-writer';

    public function __construct(private readonly RelayReader $reader) {}

    /**
     * The keys the site lists: every active mute and ban, once each, sorted,
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
        // Sorted here, not by the database: the comparison with a relay version must not hang on a collation.
        sort($keys, SORT_STRING);
        $admins = $keys === [] ? [] : Admin::query()->whereIn('pubkey', $keys)->pluck('pubkey')->all();

        return array_values(array_filter(
            $keys,
            fn (string $pubkey): bool => NostrKeys::isHexPubkey($pubkey) && $pubkey !== $leaguePubkey && ! Board::contains($pubkey) && ! in_array($pubkey, $admins, true),
        ));
    }

    /**
     * Read the newest list, carry it over with the site's keys, and sign,
     * store and queue a new version when that differs from what the relays
     * hold. Null when nothing was signed.
     *
     * @throws MuteListUnreadable when no relay answered the read
     */
    public function publish(): ?NostrEvent
    {
        $league = config('esports.league.mute_list') === true ? LeagueKey::fromConfig() : null;
        $relays = array_values(array_filter((array) config('esports.relays', []), is_string(...)));

        if ($league === null || $relays === []) {
            return null;
        }

        return Cache::lock(self::LOCK, 60)->block(30, function () use ($league, $relays): ?NostrEvent {
            $read = $this->reader->fetchCounted([['kinds' => [self::KIND], 'authors' => [$league->pubkey()], 'limit' => 1]], $relays);

            if ($read['answered'] === 0) {
                throw new MuteListUnreadable('No relay answered the read of the league mute list, so nothing was signed.');
            }

            $remote = $read['events'][0] ?? null;
            $stored = self::newest($league->pubkey());
            $storedEvent = $stored === null ? null : SignedEvent::fromInput($stored->payload());
            $base = self::newer($remote, $storedEvent);

            $entries = self::entries($league->pubkey());
            $managed = array_fill_keys([...$entries, ...($stored === null ? [] : self::siteKeys($stored))], true);
            $tags = self::merge($base === null ? [] : $base->tags, $managed, $entries);
            $content = $base === null ? '' : $base->content;

            if ($remote !== null && $remote->tags === $tags && $remote->content === $content) {
                return null;
            }

            // The site's own newest version is the list, but the relays answered without it: send that one again.
            if ($stored !== null && $storedEvent !== null && $base === $storedEvent && $storedEvent->tags === $tags && $storedEvent->content === $content) {
                PublishNostrEvent::dispatch($stored);

                return null;
            }

            $last = max($remote === null ? 0 : $remote->createdAt, $storedEvent === null ? 0 : $storedEvent->createdAt);
            $now = now()->getTimestamp();

            // Never dated ahead of the clock for a list of this second: wait for the next one.
            if ($last >= $now && $last < $now + self::MAX_WAIT) {
                Sleep::until(CarbonImmutable::createFromTimestamp($last + 1));
            } elseif ($last >= $now) {
                Log::warning('League mute list: the newest list is dated in the future, the new version is dated after it', ['created_at' => $last, 'now' => $now]);
            }

            $signedAt = max(now()->getTimestamp(), $last + 1);

            return DB::transaction(function () use ($league, $tags, $content, $signedAt, $entries): NostrEvent {
                $event = $league->publish(self::KIND, $tags, $content, $signedAt);
                DB::table('league_mute_list_versions')->insert([
                    'nostr_event_id' => $event->id,
                    'site_keys' => (string) json_encode($entries),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $event;
            });
        });
    }

    /**
     * The tags of a new version: the base's tags without the site's `p`
     * tags, exact duplicates once, in their order; then one `p` per site key.
     *
     * @param  list<list<string>>  $base
     * @param  array<string, true>  $managed  keys the site manages
     * @param  list<string>  $entries  keys the site lists now
     * @return list<list<string>>
     */
    public static function merge(array $base, array $managed, array $entries): array
    {
        $tags = [];
        $seen = [];

        foreach ($base as $tag) {
            if (($tag[0] ?? null) === 'p' && isset($managed[$tag[1] ?? ''])) {
                continue;
            }

            $key = (string) json_encode($tag);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $tags[] = $tag;
            }
        }

        foreach ($entries as $pubkey) {
            $tags[] = ['p', $pubkey];
        }

        return $tags;
    }

    /** The newest stored version of the league's mute list (highest `created_at`, ties to the lower id, NIP-01). */
    public static function newest(string $leaguePubkey): ?NostrEvent
    {
        return NostrEvent::query()->where('pubkey', $leaguePubkey)->where('kind', self::KIND)
            ->orderByDesc('signed_at')->orderBy('event_id')->first();
    }

    /**
     * The keys the site added to a stored version. Without a record (never
     * expected): its `p` keys that ever had a moderation row here.
     *
     * @return list<string>
     */
    public static function siteKeys(NostrEvent $version): array
    {
        $recorded = DB::table('league_mute_list_versions')->where('nostr_event_id', $version->id)->value('site_keys');

        if (is_string($recorded)) {
            return array_values(array_filter((array) json_decode($recorded, true), is_string(...)));
        }

        $listed = self::listed($version);

        return $listed === [] ? [] : array_values(array_map(strval(...), PubkeyModeration::query()->whereIn('pubkey', $listed)->distinct()->pluck('pubkey')->all()));
    }

    /**
     * The sorted, distinct keys of a version's public `p` tags.
     *
     * @return list<string>
     */
    public static function listed(NostrEvent $event): array
    {
        $tags = (array) ($event->payload()['tags'] ?? []);

        return self::keysOf(array_values(array_filter($tags, is_array(...))));
    }

    /**
     * @param  array<int, array<mixed>>  $tags
     * @return list<string>
     */
    private static function keysOf(array $tags): array
    {
        $keys = [];

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null)) {
                $keys[$tag[1]] = true;
            }
        }

        $keys = array_map(strval(...), array_keys($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** The newer of two versions: higher `created_at`, ties to the lower id (NIP-01). */
    private static function newer(?SignedEvent $a, ?SignedEvent $b): ?SignedEvent
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        if ($a->id === $b->id) {
            return $b;
        }

        return ([$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]) <= 0 ? $a : $b;
    }
}
