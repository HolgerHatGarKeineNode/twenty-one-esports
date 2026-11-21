<?php

namespace App\Support\FairPlay;

use App\Models\AccountLink;
use App\Models\FairPlayBar;
use App\Models\ImpersonationHold;
use App\Models\ListedProfile;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustJob;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Normalizer;

/**
 * The impersonation hold of rated play (plan "Profile standard and
 * impersonation", P2): an account that looks like another account the league
 * knows is held from new rated play until an admin decided
 * ({@see FairPlay::barred()}, kind `held`).
 *
 * Detection runs on login and on every applied profile change
 * ({@see check()}). It compares the account with every other account of the
 * league that has a cached profile (our players, `users.name`,
 * `users.picture`) and with the stored kind-0 profiles of accounts Verein
 * members list on their league opponent lists that never logged in
 * ({@see ListedProfiles}, D13, read from the relays by a scheduled job, never
 * here). The league reads no kind-3 follow lists.
 *
 * - **name**: the two names read alike ({@see nameKey()}: Unicode NFKC, case
 *   folded, diacritics dropped, Cyrillic and Greek look-alikes folded to
 *   Latin, everything but letters and digits dropped, then the NIP-05
 *   skeleton: `0`→o, `1`/`l`→i, `rn`→m, runs of one letter once). A name
 *   whose key is shorter than {@see MIN_NAME_LENGTH} characters never matches,
 *   nor one longer than {@see MAX_NAME_KEY_LENGTH} (only compatibility
 *   characters that NFKC spells out reach it: U+FDFA alone is 18 letters).
 * - **picture**: the two picture URLs are byte-for-byte equal.
 *
 * Excluded: the account itself and every account of its person (an active
 * link either way, {@see AccountLinks}). Only the account WITHOUT precedence
 * is held (D12, user 2026-11-21): a Verein member (`users.is_member`) has
 * precedence over a non-member; otherwise the account that carried the
 * matching name (resp. picture) first, as the league saw it, then the one
 * known to the league first, then the lower id. So an old account that
 * renames to a name another account had before is held too (user
 * 2026-11-21), and the original's own later refresh never holds it. Carried
 * since: the time {@see noteProfile()} / {@see noteListed()} noted when the
 * league stored the profile (the account's known-since time for the first
 * name or picture it carried, the time of storing for a changed one); with
 * no note (a profile stored before the notes began), the known-since time.
 * Known since: `users.created_at`, or the first-seen time of its listed
 * profile when that is earlier (the league saw the key on a member's list
 * before it logged in). Being on a member's opponent
 * list gives no precedence of its own: a copycat one member accepted is
 * held like any other. A listed profile without a login is never held; it
 * holds a non-member whose account is younger than its first-seen time.
 * So the original keeps playing when a look-alike appears, and its own
 * profile refresh never holds it.
 *
 * A hit writes one {@see ImpersonationHold} per matched account. No new hold
 * is raised while one for the same pair is open, nor after a release for the
 * same name skeleton and picture URL: only a changed name or picture that
 * matches anew raises one. A link or a bar ends the hold; should the same
 * match come back after the bar ended or the link was undone, it is raised
 * again.
 *
 * Behind the switch `esports.fair_play.impersonation.enabled` (off by
 * default): off, nothing here compares or holds anybody; only the notes of
 * when an account first carried a name or picture are written, so the
 * precedence is right on the day the switch goes on.
 */
final class Impersonation
{
    /** The shortest name key that is compared at all: `al`, `ed` or an emoji-only name match nobody. */
    public const MIN_NAME_LENGTH = 3;

    /**
     * The longest name key that is compared and stored. A name is at most 100
     * characters, but NFKC spells some out (U+FDFA is 18 letters): 100 of them
     * read as a 1400-letter key, which no column index takes on Postgres.
     */
    public const MAX_NAME_KEY_LENGTH = 300;

    /** The parts of a profile that are compared, as noted in `profile_values_seen`. */
    private const NAME = 'name';

    private const PICTURE = 'picture';

    /** The NIP-56 report type the decision view counts. */
    public const REPORT_TYPE = 'impersonation';

    /**
     * Look-alike letters after NFKC and lowercasing: Cyrillic and Greek
     * letters that read as a Latin one in a name (lowercased capitals
     * included: `в` from `В` reads as b, `н` from `Н` as h).
     */
    private const LOOK_ALIKES = [
        'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'һ' => 'h', 'н' => 'h', 'і' => 'i', 'ї' => 'i', 'ј' => 'j', 'к' => 'k',
        'ӏ' => 'l', 'м' => 'm', 'о' => 'o', 'р' => 'p', 'ԛ' => 'q', 'с' => 'c', 'ѕ' => 's', 'т' => 't', 'у' => 'y', 'х' => 'x',
        'ԁ' => 'd', 'ԝ' => 'w', 'ɡ' => 'g', 'ɑ' => 'a', 'ı' => 'i',
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't',
        'υ' => 'u', 'χ' => 'x', 'ω' => 'w',
    ];

    public function __construct(private AccountLinks $links, private Bars $bars) {}

    public static function enabled(): bool
    {
        return (bool) config('esports.fair_play.impersonation.enabled');
    }

    /**
     * A name as it reads, for the comparison: empty when nothing of it is
     * left. `Оdеll` (Cyrillic О and е), `0DELL`, `Ödell` and `o dell` all
     * read as `odel`.
     */
    public static function nameKey(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            return '';
        }

        $name = strtr(mb_strtolower((string) (Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name)), self::LOOK_ALIKES);
        $name = (string) preg_replace('/\p{Mn}+/u', '', (string) (Normalizer::normalize($name, Normalizer::FORM_D) ?: $name));
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name);

        if ($name === '') {
            return '';
        }

        // The NIP-05 skeleton's folds, multibyte-safe: its byte-wise run collapse splits a CJK character into invalid UTF-8.
        $name = strtr(str_replace('rn', 'm', strtr($name, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't'])), ['l' => 'i']);

        return (string) preg_replace('/(.)\1+/u', '$1', $name);
    }

    /** Whether this name key is long enough, and not too long, to be compared. */
    public static function comparable(string $key): bool
    {
        $length = mb_strlen($key);

        return $length >= self::MIN_NAME_LENGTH && $length <= self::MAX_NAME_KEY_LENGTH;
    }

    /**
     * The pubkeys among these with an open hold. Empty, without a query,
     * while the switch is off.
     *
     * @param  list<string>  $pubkeys
     * @return list<string>
     */
    public static function held(array $pubkeys): array
    {
        if (! self::enabled() || $pubkeys === []) {
            return [];
        }

        return array_values(array_unique(array_map(strval(...), ImpersonationHold::query()->open()->whereIn('pubkey', $pubkeys)->pluck('pubkey')->all())));
    }

    /**
     * Compare this account with the others and hold it for every account it
     * looks like that has precedence over it. Returns the holds written now.
     * Nothing runs while the switch is off.
     *
     * @return list<ImpersonationHold>
     */
    public static function check(User $user): array
    {
        if (! self::enabled()) {
            return [];
        }

        $key = self::nameKey($user->name);
        $key = self::comparable($key) ? $key : null;
        $picture = trim((string) $user->picture);
        $picture = $picture === '' ? null : $picture;

        if ($key === null && $picture === null) {
            return [];
        }

        $person = self::person($user->pubkey);
        $matches = [];

        $candidates = User::query()->whereKeyNot($user->id)->whereNotIn('pubkey', $person)
            ->where(fn ($query) => $query->when($key !== null, fn ($query) => $query->whereNotNull('name'))->when($picture !== null, fn ($query) => $query->orWhere('picture', $picture)))
            ->select(['id', 'pubkey', 'name', 'picture', 'created_at', 'is_member']);

        foreach ($candidates->lazyById(500) as $other) {
            $name = $key !== null && self::nameKey($other->name) === $key;
            $same = $picture !== null && $other->picture === $picture;

            if ($name || $same) {
                $matches[$other->pubkey] = ['user' => $other, 'listed' => null, 'name' => $name, 'picture' => $same];
            }
        }

        // Listed by a member, never logged in (D13): the stored key and picture, compared in the query.
        $listed = ListedProfile::query()->whereNull('unlisted_at')->whereNotIn('pubkey', $person)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.pubkey', 'listed_profiles.pubkey'))
            ->where(fn ($query) => $query->when($key !== null, fn ($query) => $query->where('name_key', $key))->when($picture !== null, fn ($query) => $query->orWhere('picture', $picture)))
            ->get(['pubkey', 'name_key', 'picture', 'first_seen_at']);

        foreach ($listed as $profile) {
            $matches[$profile->pubkey] = ['user' => null, 'listed' => $profile, 'name' => $key !== null && $profile->name_key === $key, 'picture' => $picture !== null && $profile->picture === $picture];
        }

        if ($matches === []) {
            return [];
        }

        $since = self::knownSince([$user, ...array_values(array_filter(array_column($matches, 'user')))]);
        $pubkeys = [$user->pubkey, ...array_map(strval(...), array_keys($matches))];
        $carried = ['name' => self::carriedSince($pubkeys, self::NAME, $key), 'picture' => self::carriedSince($pubkeys, self::PICTURE, $picture)];
        $kept = [];

        // Per matched part: only what the other account carried first holds this one.
        foreach ($matches as $matchedPubkey => $match) {
            foreach ([self::NAME, self::PICTURE] as $part) {
                if ($match[$part]) {
                    $match[$part] = $match['user'] instanceof User
                        ? self::precedes($match['user'], $user, $since, $carried[$part])
                        : self::listedPrecedes($match['listed'], $user, $since, $carried[$part]);
                }
            }

            if ($match['name'] || $match['picture']) {
                $kept[$matchedPubkey] = $match;
            }
        }

        $matches = $kept;

        if ($matches === []) {
            return [];
        }

        return DB::transaction(function () use ($user, $matches, $key, $picture): array {
            // One check at a time for this account: the holds below are read under the lock.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $existing = ImpersonationHold::query()->where('pubkey', $user->pubkey)->whereIn('matched_pubkey', array_keys($matches))
                ->where(fn ($query) => $query->whereNull('decided_at')->orWhereIn('decision', [ImpersonationHold::RELEASED, ImpersonationHold::BARRED]))
                ->get()->groupBy('matched_pubkey');
            $barRunning = FairPlayBar::query()->running()->where('pubkey', $user->pubkey)->exists();
            $written = [];

            foreach ($matches as $matchedPubkey => $match) {
                $rows = $existing->get($matchedPubkey) ?? collect();

                if ($rows->contains(fn (ImpersonationHold $hold): bool => $hold->isOpen())) {
                    continue;
                }

                // Barred for this match and the bar still runs: the bar is the decision, no new hold until it ended.
                if ($barRunning && $rows->contains(fn (ImpersonationHold $hold): bool => $hold->decision === ImpersonationHold::BARRED)) {
                    continue;
                }

                // Released before: raised again only for what matches now and was not released for this very value.
                $released = $rows->filter(fn (ImpersonationHold $hold): bool => $hold->decision === ImpersonationHold::RELEASED);
                $nameReleased = ! $match['name'] || $released->contains(fn (ImpersonationHold $hold): bool => $hold->matchesName() && $hold->name_skeleton === $key);
                $pictureReleased = ! $match['picture'] || $released->contains(fn (ImpersonationHold $hold): bool => $hold->matchesPicture() && $hold->picture === $picture);

                if ($nameReleased && $pictureReleased) {
                    continue;
                }

                $written[] = ImpersonationHold::query()->create([
                    'user_id' => $user->id,
                    'pubkey' => $user->pubkey,
                    'matched_user_id' => $match['user']?->id,
                    'matched_pubkey' => $matchedPubkey,
                    'matched' => $match['name'] && $match['picture'] ? ImpersonationHold::BOTH : ($match['name'] ? ImpersonationHold::NAME : ImpersonationHold::PICTURE),
                    'name_skeleton' => $match['name'] ? $key : null,
                    'picture' => $match['picture'] ? $picture : null,
                    'detected_at' => now(),
                ]);
            }

            return $written;
        });
    }

    /**
     * Kind-1984 reports (NIP-56) of type `impersonation` against this pubkey
     * from accounts ranked {@see TrustJob::REPORTER_MINIMUM} or more now, as
     * the league archived them (`nostr_events`): each reporting account
     * counts once, however many reports it filed.
     */
    public static function reports(string $pubkey): int
    {
        $authors = [];
        $reports = NostrEvent::query()->where('kind', TrustJob::REPORT)
            ->whereIn('pubkey', TrustRank::query()->where('rank', '>=', TrustJob::REPORTER_MINIMUM)->select('pubkey'))
            ->where('raw', 'like', '%'.$pubkey.'%')->get(['pubkey', 'raw']);

        foreach ($reports as $report) {
            $event = json_decode($report->raw, true);

            foreach (is_array($event) && is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'p' && ($tag[1] ?? null) === $pubkey && ($tag[2] ?? null) === self::REPORT_TYPE && $report->pubkey !== $pubkey) {
                    $authors[$report->pubkey] = true;
                }
            }
        }

        return count($authors);
    }

    /**
     * The accounts both list on their league opponent lists, and whether they
     * list each other.
     *
     * @return array{shared: list<string>, mutual: bool}
     */
    public static function overlap(string $a, string $b): array
    {
        $lists = OpponentLists::forLeague();

        if ($lists === null) {
            return ['shared' => [], 'mutual' => false];
        }

        $entries = $lists->newestEntriesOf([$a, $b]);
        $ofA = $entries[$a] ?? [];
        $ofB = $entries[$b] ?? [];

        return [
            'shared' => array_values(array_diff(array_intersect($ofA, $ofB), [$a, $b])),
            'mutual' => in_array($b, $ofA, true) && in_array($a, $ofB, true),
        ];
    }

    /**
     * The hold ends, the account plays rated again; the same name and
     * picture do not raise it again.
     *
     * @throws FairPlayRefused
     */
    public function release(User $admin, ImpersonationHold $hold, string $note = ''): void
    {
        $this->assertMayDecide($admin, $hold);
        $note = $this->note($note, required: false);

        DB::transaction(function () use ($admin, $hold, $note): void {
            $this->decide($admin, ImpersonationHold::query()->whereKey($hold->id), $hold, ImpersonationHold::RELEASED, $note);
        });
    }

    /**
     * The held account is a second account of the matched one: linked to it
     * as the main account ({@see AccountLinks::link()}, which voids their
     * results against each other and withholds its prizes). Ends every open
     * hold of the account.
     *
     * @return array{link: AccountLink, voided: int, withheld: int}
     *
     * @throws FairPlayRefused
     */
    public function link(User $admin, ImpersonationHold $hold, string $reason): array
    {
        $this->assertMayDecide($admin, $hold);
        $reason = $this->note($reason, required: true);

        return DB::transaction(function () use ($admin, $hold, $reason): array {
            $this->assertOpen($hold);
            $linked = $this->links->link($admin, $hold->matched_pubkey, $hold->pubkey, $reason);
            $this->decide($admin, ImpersonationHold::query()->where('pubkey', $hold->pubkey), $hold, ImpersonationHold::LINKED, $reason);

            return $linked;
        }, 3);
    }

    /**
     * The held account is barred from rated play by hand ({@see Bars::set()})
     * until `$until`, or the end of the live season. Ends every open hold of
     * the account.
     *
     * @throws FairPlayRefused
     */
    public function bar(User $admin, ImpersonationHold $hold, string $reason, ?CarbonImmutable $until = null): void
    {
        $this->assertMayDecide($admin, $hold);
        $reason = $this->note($reason, required: true);

        DB::transaction(function () use ($admin, $hold, $reason, $until): void {
            $this->assertOpen($hold);
            $this->bars->set($admin, $hold->pubkey, $reason, $until);
            $this->decide($admin, ImpersonationHold::query()->where('pubkey', $hold->pubkey), $hold, ImpersonationHold::BARRED, $reason);
        });
    }

    /**
     * Mark the open holds `$holds` selects as decided, and log it. Refuses
     * when the hold itself was decided meanwhile.
     *
     * @param  Builder<ImpersonationHold>  $holds
     *
     * @throws FairPlayRefused
     */
    private function decide(User $admin, $holds, ImpersonationHold $hold, string $decision, string $note): void
    {
        $this->assertOpen($hold);

        $ended = $holds->open()->update([
            'decided_at' => now(),
            'decided_by_id' => $admin->id,
            'decided_by_pubkey' => $admin->pubkey,
            'decision' => $decision,
            'note' => $note === '' ? null : $note,
        ]);

        Log::info('Impersonation hold decided', [
            'hold' => $hold->id, 'pubkey' => $hold->pubkey, 'matched_pubkey' => $hold->matched_pubkey,
            'decision' => $decision, 'holds_ended' => $ended, 'admin' => $admin->pubkey,
        ]);
    }

    /** @throws FairPlayRefused */
    private function assertOpen(ImpersonationHold $hold): void
    {
        if (ImpersonationHold::query()->open()->whereKey($hold->id)->lockForUpdate()->doesntExist()) {
            throw new FairPlayRefused(__('This hold was decided already.'));
        }
    }

    /**
     * Admins only, and never about their own accounts or their own clan
     * (the guard of {@see Bars}).
     *
     * @throws FairPlayRefused
     */
    private function assertMayDecide(User $admin, ImpersonationHold $hold): void
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new FairPlayRefused(__('Only admins can decide about a held account.'));
        }

        if (in_array($admin->pubkey, [$hold->pubkey, $hold->matched_pubkey], true)) {
            throw new FairPlayRefused(__('You cannot decide about your own accounts. Another admin has to.'));
        }

        if (TrustAdmin::isOwn($admin, [$hold->pubkey])) {
            throw new FairPlayRefused(__('You cannot decide about yourself or your own clan. Another admin has to.'));
        }
    }

    /** @throws FairPlayRefused */
    private function note(string $note, bool $required): string
    {
        $note = trim($note);

        if (($required && $note === '') || mb_strlen($note) > Bars::REASON_MAX) {
            throw new FairPlayRefused(__('Give a reason, up to :max characters.', ['max' => Bars::REASON_MAX]));
        }

        return $note;
    }

    /**
     * The pubkeys of this account's person: itself, its main account and the
     * main account's other linked accounts.
     *
     * @return list<string>
     */
    private static function person(string $pubkey): array
    {
        $main = AccountLink::query()->active()->where('linked_pubkey', $pubkey)->value('main_pubkey') ?? $pubkey;
        $linked = AccountLink::query()->active()->where('main_pubkey', $main)->pluck('linked_pubkey')->all();

        return array_values(array_unique(array_map(strval(...), [$pubkey, $main, ...$linked])));
    }

    /**
     * Since when the league knows each of these accounts: `users.created_at`,
     * or its listed profile's first-seen time when that is earlier.
     *
     * @param  list<User>  $users
     * @return array<string, array{int, int}> pubkey => [timestamp, id]
     */
    private static function knownSince(array $users): array
    {
        $firstSeen = ListedProfile::query()->whereIn('pubkey', array_map(fn (User $user): string => $user->pubkey, $users))->pluck('first_seen_at', 'pubkey');
        $since = [];

        foreach ($users as $user) {
            $listed = $firstSeen->get($user->pubkey);
            $times = array_filter([$user->created_at?->getTimestamp(), $listed === null ? null : CarbonImmutable::parse($listed)->getTimestamp()], is_int(...));
            $since[$user->pubkey] = [$times === [] ? PHP_INT_MAX : min($times), $user->id];
        }

        return $since;
    }

    /**
     * Note since when this player carries the name and picture the profile
     * cache just stored, `$previousName` and `$previousPicture` being what
     * it held before. Runs whatever the switch says.
     */
    public static function noteProfile(User $user, ?string $previousName, ?string $previousPicture, bool $firstProfile = false): void
    {
        self::noteCarried($user->pubkey, self::keyOrNull($previousName), self::keyOrNull($user->name), self::urlOrNull($previousPicture), self::urlOrNull($user->picture), $firstProfile,
            fn (): CarbonImmutable => CarbonImmutable::createFromTimestamp(min(self::knownSince([$user])[$user->pubkey][0], now()->getTimestamp())));
    }

    /** The same for a listed profile ({@see ListedProfiles}), known to the league since its first-seen time. */
    public static function noteListed(ListedProfile $profile, ?string $previousKey, ?string $previousPicture, bool $firstProfile = false): void
    {
        self::noteCarried($profile->pubkey, $previousKey, $profile->name_key, self::urlOrNull($previousPicture), self::urlOrNull($profile->picture), $firstProfile,
            fn (): CarbonImmutable => CarbonImmutable::instance($profile->first_seen_at));
    }

    /**
     * One row per account and value, never updated: a value from the very
     * first profile the league stores for an account (or the same one again)
     * counts from the time the league knows the account; any other new value
     * counts from now. So an old account that had no usable name or picture
     * and takes one later carries it from now, not from its registration.
     *
     * @param  Closure(): CarbonImmutable  $knownSince
     */
    private static function noteCarried(string $pubkey, ?string $previousKey, ?string $key, ?string $previousPicture, ?string $picture, bool $firstProfile, Closure $knownSince): void
    {
        $rows = [];

        foreach ([[self::NAME, $previousKey, $key], [self::PICTURE, $previousPicture, $picture]] as [$kind, $previous, $value]) {
            if ($value === null) {
                continue;
            }

            $rows[] = [
                'pubkey' => $pubkey,
                'kind' => $kind,
                'value' => $value,
                'value_hash' => hash('sha256', $value),
                'first_seen_at' => ($previous === null && $firstProfile) || $previous === $value ? $knownSince() : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('profile_values_seen')->insertOrIgnore($rows);
        }
    }

    /**
     * Since when each of these accounts carries this name key or picture, as
     * noted; an account without a note is missing.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, int> pubkey => timestamp
     */
    private static function carriedSince(array $pubkeys, string $kind, ?string $value): array
    {
        if ($value === null || $pubkeys === []) {
            return [];
        }

        return DB::table('profile_values_seen')->whereIn('pubkey', $pubkeys)->where('kind', $kind)->where('value_hash', hash('sha256', $value))
            ->pluck('first_seen_at', 'pubkey')->map(fn (mixed $at): int => CarbonImmutable::parse((string) $at)->getTimestamp())->all();
    }

    private static function keyOrNull(?string $name): ?string
    {
        $key = self::nameKey($name);

        return self::comparable($key) ? $key : null;
    }

    private static function urlOrNull(?string $picture): ?string
    {
        $picture = trim((string) $picture);

        return $picture === '' ? null : $picture;
    }

    /**
     * Whether `$other` has precedence over `$user` for one matched part
     * (D12): a Verein member over a non-member, else the one that carried it
     * first, then the one the league knows first, then the lower id.
     *
     * @param  array<string, array{int, int}>  $since
     * @param  array<string, int>  $carried
     */
    private static function precedes(User $other, User $user, array $since, array $carried): bool
    {
        if ((bool) $other->is_member !== (bool) $user->is_member) {
            return (bool) $other->is_member;
        }

        $at = fn (User $account): array => [$carried[$account->pubkey] ?? $since[$account->pubkey][0], ...$since[$account->pubkey]];

        return $at($other) < $at($user);
    }

    /**
     * Whether a listed profile without a login has precedence over `$user`
     * for one matched part: never over a member, else when it carried it
     * first, or as early and the league saw the key on a member's list
     * before it knew `$user`.
     *
     * @param  array<string, array{int, int}>  $since
     * @param  array<string, int>  $carried
     */
    private static function listedPrecedes(?ListedProfile $listed, User $user, array $since, array $carried): bool
    {
        if ($listed === null || $user->is_member) {
            return false;
        }

        $listedAt = $carried[$listed->pubkey] ?? $listed->first_seen_at->getTimestamp();
        $userAt = $carried[$user->pubkey] ?? $since[$user->pubkey][0];

        return $listedAt < $userAt || ($listedAt === $userAt && $listed->first_seen_at->getTimestamp() < $since[$user->pubkey][0]);
    }
}
