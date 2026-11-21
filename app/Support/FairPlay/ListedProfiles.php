<?php

namespace App\Support\FairPlay;

use App\Models\ListedProfile;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\ProfileCache;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\OpponentLists;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The profiles of accounts Verein members list on their league opponent
 * lists (plan "Profile standard and impersonation", P3, D13), so the
 * impersonation check compares a new account with an original that never
 * logged in ({@see Impersonation::check()}).
 *
 * Stored in a table of their own (`listed_profiles`), not in `users`: a
 * `users` row is an account that logged in, and every reader of `users`
 * (the profile pages, the lists, the trust job) would meet strangers who
 * never did. The rows hold only what the check compares (name, its key,
 * picture URL) and when the league first saw the key.
 *
 * **First seen** is the league's own time, never one the sender sets: the
 * `created_at` of the oldest archived version (`nostr_events`, kind 30000,
 * `d` `esports/<league key>`) of a current member's list that names the
 * key, i.e. when the league first stored such a list. A key no archived
 * version names (the current row came without an archive) gets the time
 * the row is written. It never moves later: a row stays when the key drops
 * off every list (`unlisted_at`) or the account logs in, so a later look-
 * alike cannot inherit precedence by waiting.
 *
 * {@see refresh()} reads kind 0 from the profile relays in batches, for the
 * listed keys without a `users` row whose last read is older than a day,
 * at most {@see PER_RUN} keys a run. A batch no relay answered is left for
 * the next run and reported; it never holds anybody and never blocks a
 * login (the check only reads this table). A profile that changed name or
 * picture is compared with our accounts right away, so a look-alike that
 * logged in before the original's profile arrived is held too.
 */
final class ListedProfiles
{
    /** Authors per kind-0 filter. */
    public const BATCH = 100;

    /** Keys read per run at most. */
    public const PER_RUN = 500;

    /** A key is read again after this many hours. */
    public const REFRESH_HOURS = 24;

    public function __construct(private RelayReader $relays) {}

    /**
     * Bring the rows in line with the members' newest lists, local only:
     * new keys get a row with their first-seen time, keys no list names any
     * more are marked unlisted, keys listed again are compared again.
     *
     * @return array{listed: int, added: int, unlisted: int}
     */
    public static function sync(): array
    {
        $lists = OpponentLists::forLeague();
        $listed = [];

        if ($lists !== null) {
            foreach ($lists->newestEntriesOf(User::query()->where('is_member', true)->select('pubkey')) as $entries) {
                foreach ($entries as $entry) {
                    $listed[$entry] = true;
                }
            }
        }

        $listed = array_keys($listed);
        $known = [];

        foreach (array_chunk($listed, 500) as $chunk) {
            foreach (ListedProfile::query()->whereIn('pubkey', $chunk)->pluck('pubkey') as $pubkey) {
                $known[(string) $pubkey] = true;
            }
        }

        $new = array_values(array_filter($listed, fn (string $pubkey): bool => ! isset($known[$pubkey])));
        $firstSeen = $lists === null ? [] : self::firstSeen($lists, $new);
        $now = now();

        foreach (array_chunk($new, 500) as $chunk) {
            ListedProfile::query()->insert(array_map(fn (string $pubkey): array => [
                'pubkey' => $pubkey,
                'first_seen_at' => $firstSeen[$pubkey] ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $unlisted = ListedProfile::query()->whereNull('unlisted_at')->whereNotIn('pubkey', $listed)->update(['unlisted_at' => $now]);

        foreach (array_chunk($listed, 500) as $chunk) {
            ListedProfile::query()->whereNotNull('unlisted_at')->whereIn('pubkey', $chunk)->update(['unlisted_at' => null]);
        }

        return ['listed' => count($listed), 'added' => count($new), 'unlisted' => $unlisted];
    }

    /**
     * Read the due profiles from the relays and store them; compare our
     * accounts with every profile whose name or picture changed. A profile
     * the database refuses is reported (`skipped`) and checked like the rest
     * of its batch, so it is not read first again on every run.
     *
     * @return array{asked: int, stored: int, skipped: int, failed: int, held: int}
     */
    public function refresh(): array
    {
        $due = ListedProfile::query()->whereNull('unlisted_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.pubkey', 'listed_profiles.pubkey'))
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subHours(self::REFRESH_HOURS)))
            ->orderByRaw('checked_at is not null')->orderBy('checked_at')->orderBy('id')
            ->limit(self::PER_RUN)->get()->keyBy('pubkey');

        $stored = 0;
        $skipped = 0;
        $failed = 0;
        $changed = [];

        foreach ($due->chunk(self::BATCH) as $batch) {
            $read = $this->relays->fetchCounted([['kinds' => [0], 'authors' => $batch->keys()->all(), 'limit' => $batch->count()]], self::relays());

            // No relay answered: nothing is known about these keys, they stay due. A failed read holds nobody.
            if ($read['answered'] === 0) {
                $failed++;
                Log::warning('Listed profiles: no relay answered a kind-0 read', ['keys' => $batch->count()]);

                continue;
            }

            foreach ($read['events'] as $event) {
                $row = $batch->get($event->pubkey);

                if (! $row instanceof ListedProfile) {
                    continue;
                }

                // One profile the database refuses never holds back its batch: it is reported and checked like the rest.
                try {
                    if (! $this->store($row, $event)) {
                        continue;
                    }
                } catch (Throwable $exception) {
                    $skipped++;
                    report($exception);
                    Log::warning('Listed profiles: a kind-0 profile could not be stored', ['pubkey' => $row->pubkey, 'error' => $exception->getMessage()]);

                    continue;
                }

                $stored++;

                if ($row->wasChanged(['name_key', 'picture'])) {
                    $changed[] = $row;
                }
            }

            ListedProfile::query()->whereIn('id', $batch->pluck('id'))->update(['checked_at' => now()]);
        }

        return ['asked' => $due->count(), 'stored' => $stored, 'skipped' => $skipped, 'failed' => $failed, 'held' => self::compare($changed)];
    }

    /**
     * The relays the league reads profiles from (the payouts page's set).
     *
     * @return list<string>
     */
    public static function relays(): array
    {
        return array_values(array_unique(array_filter([
            ...(array) config('esports.profile_relays', []),
            ...(array) config('esports.stream_chat.indexer_relays', []),
            ...(array) config('esports.relays', []),
        ], 'is_string')));
    }

    /**
     * The oldest archive time of a current member's list version naming each
     * of these keys.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, Carbon>
     */
    private static function firstSeen(OpponentLists $lists, array $pubkeys): array
    {
        if ($pubkeys === []) {
            return [];
        }

        $wanted = array_fill_keys($pubkeys, true);
        $seen = [];
        $versions = NostrEvent::query()->where('kind', OpponentLists::KIND)->where('d', $lists->d())
            ->whereIn('pubkey', User::query()->where('is_member', true)->select('pubkey'))
            ->orderBy('created_at')->orderBy('id')->select(['id', 'pubkey', 'kind', 'raw', 'created_at']);

        foreach ($versions->lazyById(500) as $version) {
            foreach (OpponentLists::entries($version) as $entry) {
                if (isset($wanted[$entry]) && $version->created_at !== null
                    && (! isset($seen[$entry]) || $version->created_at->lt($seen[$entry]))) {
                    $seen[$entry] = $version->created_at;
                }
            }
        }

        return $seen;
    }

    /** Store a newer, well-formed, validly signed profile. */
    private function store(ListedProfile $row, SignedEvent $event): bool
    {
        $storedAt = $row->profile_event_at?->getTimestamp();

        if ($event->kind !== 0 || $event->pubkey !== $row->pubkey
            || strlen($event->content) > ProfileCache::MAX_CONTENT_BYTES
            || $event->createdAt > now()->getTimestamp() + ProfileCache::MAX_AHEAD_SECONDS
            || ($storedAt !== null && $event->createdAt <= $storedAt)) {
            return false;
        }

        $metadata = json_decode($event->content, true);

        if (! is_array($metadata) || ! $event->hasValidSignature()) {
            return false;
        }

        $name = ProfileCache::name($metadata);
        $key = Impersonation::nameKey($name);
        [$previousKey, $previousPicture] = [$row->name_key, $row->picture];

        $row->forceFill([
            'name' => $name,
            'name_key' => Impersonation::comparable($key) ? $key : null,
            'picture' => ProfileCache::httpsUrl($metadata['picture'] ?? null),
            'profile_event_at' => CarbonImmutable::createFromTimestamp($event->createdAt),
            'fetched_at' => now(),
        ])->save();

        // A failed note never undoes the stored profile: without it the precedence falls back to the first-seen time.
        rescue(fn () => Impersonation::noteListed($row, $previousKey, $previousPicture, firstProfile: $storedAt === null));

        return true;
    }

    /**
     * Run the check for every account that reads like, or shows the picture
     * of, one of these profiles. Returns the holds written.
     *
     * @param  list<ListedProfile>  $profiles
     */
    private static function compare(array $profiles): int
    {
        if ($profiles === [] || ! Impersonation::enabled()) {
            return 0;
        }

        $keys = array_fill_keys(array_filter(array_map(fn (ListedProfile $row): ?string => $row->name_key, $profiles)), true);
        $pictures = array_fill_keys(array_filter(array_map(fn (ListedProfile $row): ?string => $row->picture, $profiles)), true);
        $held = 0;

        foreach (User::query()->select(['id', 'pubkey', 'name', 'picture', 'created_at', 'is_member'])->lazyById(500) as $user) {
            if (isset($keys[Impersonation::nameKey($user->name)]) || ($user->picture !== null && isset($pictures[$user->picture]))) {
                // A failing check is reported and never stops the others; the next change checks again.
                $held += count(rescue(fn (): array => Impersonation::check(User::query()->findOrFail($user->id)), []));
            }
        }

        return $held;
    }
}
