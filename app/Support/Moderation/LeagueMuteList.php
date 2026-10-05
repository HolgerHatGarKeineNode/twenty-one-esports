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
 * (user, 2026-10-05, option 2): its `content` (the encrypted private part)
 * is copied unchanged and never decrypted; every tag that is not one of the
 * site's own is kept verbatim (foreign public `p`, `t`, `word`, `e`,
 * `client`, ...), exact duplicates once. Then the site's `p` tags follow,
 * sorted, for every listed key the client does not already hold as a public
 * `p` of its own; a client's tag for a key the site moderates as well stays
 * as the client wrote it (hint, petname).
 *
 * The site's own tags are the exact `["p", <key>]` tags it appended to its
 * previous version, recorded per version in `league_mute_list_versions`
 * (`site_keys`; `client_keys` names the listed keys a client held). A lift
 * so removes what the site appended and never a tag a client wrote.
 *
 * Quorum (security gate 2026-10-05, F1): signing over a stale or empty read
 * would replace the client's list and wipe its private part. So the read
 * goes to the league relays minus `esports.league.mute_list_unreachable`
 * (relays measured unreachable from production), asks for the league key's
 * relay list (NIP-65 kind 10002) and its mute list in one pass, and requires
 * EOSE from every write relay of that relay list among them: the relays a
 * client writes the key's list to. No relay list, no such relay, or one of
 * them not answering: nothing is signed ({@see MuteListUnreadable}; the job
 * tries again, the hourly `esports:mute-list` run reconciles). The base is
 * the newest list over the relays read and the stored copy (higher
 * `created_at`, ties to the lower id). Right before signing the quorum is
 * read once more; a list newer than the base aborts the run.
 *
 * Dating: a new version is dated one second after its base, not at the
 * clock, so a client version newer than the base that no relay of this run
 * returned still wins (NIP-01) instead of being replaced. Only when the
 * quorum confirmed that no list exists is it dated now. Relays may refuse
 * old events (strfry, which runs most league relays: `created_at too early`
 * past `rejectEventsOlderThanSeconds`, default 94608000 s = 3 years; none of
 * the eight league relays advertised a NIP-11 `created_at_lower_limit` on
 * 2026-10-05), so a base older than BACKDATE_LIMIT is dated at the clock
 * minus that limit. A base of this second or the next is waited for rather
 * than dated ahead of the clock; one further in the future (a client clock
 * ahead) is followed and logged. A run whose list equals the newest relay
 * version (tags and content) signs nothing; when the site's own newest
 * version is the list but the relays do not have it, that version is queued
 * again instead of signing a new one. So a client edit that dropped the
 * site's keys is repaired by the next run.
 *
 * Stored in nostr_events and sent to the league relays (`esports.relays`)
 * through LeagueKey::publish and PublishNostrEvent; a relay that refuses or
 * times out is recorded per relay and never fails the run. Writes run under
 * a lock, one at a time. Without the league key, with no relay configured,
 * or with `esports.league.mute_list` off (ESPORTS_PUBLISH_MUTE_LIST=false,
 * the kill switch) nothing is read or signed, and `nostr:republish` does
 * not send the stored versions again.
 */
final class LeagueMuteList
{
    public const KIND = 10000;

    /** NIP-65 relay list: its write relays are where a client writes the key's mute list. */
    public const RELAY_LIST = 10002;

    /** Seconds the writer waits at most for a newest list dated in this very second or the next. */
    private const MAX_WAIT = 2;

    /**
     * A new version is never dated further back than this (365 days): strfry
     * refuses events older than 3 years by default, so this leaves room for
     * a relay that halved it.
     */
    public const BACKDATE_LIMIT = 31_536_000;

    /** Events kept per relay and author: one relay list and one mute list, plus room for a relay that ignores `limit`. */
    private const PER_RELAY = 3;

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
     * Read the newest list from the quorum, carry it over with the site's
     * keys, and sign, store and queue a new version when that differs from
     * what the relays hold. Null when nothing was signed.
     *
     * @throws MuteListUnreadable when the quorum could not be read, or the list changed before signing
     */
    public function publish(): ?NostrEvent
    {
        $league = config('esports.league.mute_list') === true ? LeagueKey::fromConfig() : null;
        $relays = array_values(array_filter((array) config('esports.relays', []), is_string(...)));

        if ($league === null || $relays === []) {
            return null;
        }

        return Cache::lock(self::LOCK, 120)->block(30, function () use ($league, $relays): ?NostrEvent {
            $pubkey = $league->pubkey();
            $readable = self::readableRelays($relays);
            $read = $this->reader->readEach([
                ['kinds' => [self::RELAY_LIST], 'authors' => [$pubkey], 'limit' => 1],
                ['kinds' => [self::KIND], 'authors' => [$pubkey], 'limit' => 1],
            ], $readable, self::PER_RELAY);

            $quorum = self::quorum(self::newestOf($read, self::RELAY_LIST, $pubkey), $readable);
            self::requireAnswered($read, $quorum, 'read');

            $remote = self::newestOf($read, self::KIND, $pubkey);
            $stored = self::newest($pubkey);
            $storedEvent = $stored === null ? null : SignedEvent::fromInput($stored->payload());
            $base = self::newer($remote, $storedEvent);

            $entries = self::entries($pubkey);
            $merged = self::merge($base === null ? [] : $base->tags, $stored === null ? [] : self::siteKeys($stored), $entries);
            $tags = $merged['tags'];
            $content = $base === null ? '' : $base->content;

            if ($remote !== null && $remote->tags === $tags && $remote->content === $content) {
                return null;
            }

            // The site's own newest version is the list, but the relays answered without it: send that one again.
            if ($stored !== null && $storedEvent !== null && $base === $storedEvent && $storedEvent->tags === $tags && $storedEvent->content === $content) {
                PublishNostrEvent::dispatch($stored);

                return null;
            }

            $signedAt = self::signedAt($base);
            $now = now()->getTimestamp();

            // Never dated ahead of the clock for a list of this second: wait for the next one.
            if ($signedAt > $now && $signedAt <= $now + self::MAX_WAIT) {
                Sleep::until(CarbonImmutable::createFromTimestamp($signedAt));
            } elseif ($signedAt > $now) {
                Log::warning('League mute list: the newest list is dated in the future, the new version is dated after it', ['created_at' => $signedAt - 1, 'now' => $now]);
            }

            // Right before signing, the quorum once more: a list that reached it meanwhile is the next run's base.
            $again = $this->reader->readEach([['kinds' => [self::KIND], 'authors' => [$pubkey], 'limit' => 1]], $quorum, self::PER_RELAY);
            self::requireAnswered($again, $quorum, 're-read before signing');
            $latest = self::newestOf($again, self::KIND, $pubkey);

            if ($latest !== null && self::newer($latest, $base) !== $base) {
                throw new MuteListUnreadable('A newer league mute list ('.$latest->id.') reached the relays while this version was prepared, so nothing was signed.');
            }

            return DB::transaction(function () use ($league, $tags, $content, $signedAt, $merged): NostrEvent {
                $event = $league->publish(self::KIND, $tags, $content, $signedAt);
                DB::table('league_mute_list_versions')->insert([
                    'nostr_event_id' => $event->id,
                    'site_keys' => (string) json_encode($merged['appended']),
                    'client_keys' => (string) json_encode($merged['held']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $event;
            });
        });
    }

    /**
     * The date of a new version: one second after its base, never further
     * back than BACKDATE_LIMIT; now when there is no list at all.
     */
    public static function signedAt(?SignedEvent $base): int
    {
        $now = now()->getTimestamp();

        return $base === null ? $now : max($base->createdAt + 1, $now - self::BACKDATE_LIMIT);
    }

    /**
     * The league relays the writer reads: `esports.relays` without the ones
     * in `esports.league.mute_list_unreachable`.
     *
     * @param  list<string>  $relays
     * @return list<string>
     */
    public static function readableRelays(array $relays): array
    {
        $listed = array_values(array_filter((array) config('esports.league.mute_list_unreachable', []), is_string(...)));
        $unreachable = array_fill_keys(array_map(self::normalized(...), $listed), true);

        return array_values(array_filter($relays, fn (string $relay): bool => ! isset($unreachable[self::normalized($relay)])));
    }

    /**
     * The relays that must answer: the write relays of the league key's
     * relay list (NIP-65: an `r` tag without marker or marked `write`) among
     * the readable league relays, in their configured spelling.
     *
     * @param  list<string>  $readable
     * @return non-empty-list<string>
     *
     * @throws MuteListUnreadable without a relay list, or when none of its write relays is readable
     */
    public static function quorum(?SignedEvent $relayList, array $readable): array
    {
        if ($relayList === null) {
            throw new MuteListUnreadable('The league key\'s relay list (NIP-65 kind 10002) could not be read, so no quorum is known and nothing was signed.');
        }

        $write = [];

        foreach ($relayList->tags as $tag) {
            if (($tag[0] ?? null) === 'r' && isset($tag[1]) && in_array($tag[2] ?? 'write', ['write', ''], true)) {
                $write[self::normalized($tag[1])] = true;
            }
        }

        $quorum = array_values(array_filter($readable, fn (string $relay): bool => isset($write[self::normalized($relay)])));

        if ($quorum === []) {
            throw new MuteListUnreadable('None of the league key\'s write relays (NIP-65) is a readable league relay, so nothing was signed.');
        }

        return $quorum;
    }

    /**
     * @param  array<string, list<SignedEvent>|null>  $read
     * @param  list<string>  $quorum
     *
     * @throws MuteListUnreadable when a relay of the quorum was not read to EOSE
     */
    private static function requireAnswered(array $read, array $quorum, string $what): void
    {
        $missing = array_values(array_filter($quorum, fn (string $relay): bool => ($read[$relay] ?? null) === null));

        if ($missing !== []) {
            throw new MuteListUnreadable('Relays of the quorum did not answer the '.$what.' of the league mute list ('.implode(', ', $missing).'), so nothing was signed.');
        }
    }

    /**
     * The newest event of the kind by the key over every relay read (NIP-01).
     *
     * @param  array<string, list<SignedEvent>|null>  $read
     */
    private static function newestOf(array $read, int $kind, string $pubkey): ?SignedEvent
    {
        $newest = null;

        foreach ($read as $events) {
            foreach ($events ?? [] as $event) {
                if ($event->kind === $kind && $event->pubkey === $pubkey) {
                    $newest = self::newer($event, $newest);
                }
            }
        }

        return $newest;
    }

    /** A relay URL for comparison: trimmed, lower case, without a trailing slash. */
    private static function normalized(string $relay): string
    {
        return strtolower(rtrim(trim($relay), '/'));
    }

    /**
     * The tags of a new version: the base's tags without the site's own (an
     * exact `["p", <key>]` for a key it appended to its previous version),
     * exact duplicates once, in their order; then one `p` per listed key the
     * kept tags do not name yet. A client's `p` stays verbatim, also for a
     * key the site lists: that key counts as held, not appended.
     *
     * @param  list<list<string>>  $base
     * @param  list<string>  $appended  keys the site appended to its previous version
     * @param  list<string>  $entries  keys the site lists now
     * @return array{tags: list<list<string>>, appended: list<string>, held: list<string>}
     */
    public static function merge(array $base, array $appended, array $entries): array
    {
        $ours = array_fill_keys($appended, true);
        $tags = [];
        $seen = [];
        $named = [];

        foreach ($base as $tag) {
            if (count($tag) === 2 && $tag[0] === 'p' && isset($ours[$tag[1]])) {
                continue;
            }

            $key = (string) json_encode($tag);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $tags[] = $tag;

                if (($tag[0] ?? null) === 'p' && isset($tag[1])) {
                    $named[$tag[1]] = true;
                }
            }
        }

        $added = [];
        $held = [];

        foreach ($entries as $pubkey) {
            if (isset($named[$pubkey])) {
                $held[] = $pubkey;

                continue;
            }

            $added[] = $pubkey;
            $tags[] = ['p', $pubkey];
        }

        return ['tags' => $tags, 'appended' => $added, 'held' => $held];
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
