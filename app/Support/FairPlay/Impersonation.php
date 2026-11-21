<?php

namespace App\Support\FairPlay;

use App\Models\AccountLink;
use App\Models\ImpersonationHold;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\Nostr\Nip05Names;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustJob;
use Carbon\CarbonImmutable;
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
 * league that has a cached profile: our players, among them the Verein
 * members (`users.is_member`, stored by the login's membership check) and
 * the accounts on members' league opponent lists (kind 30000, `d`
 * `esports/<league key>`, the current rows the trust job archived). The league
 * reads no kind-3 follow lists and fetches nothing from relays here: only
 * what is stored locally counts, and a profile is known only for an account
 * that logged in (`users.name`, `users.picture`).
 *
 * - **name**: the two names read alike ({@see nameKey()}: Unicode NFKC, case
 *   folded, diacritics dropped, Cyrillic and Greek look-alikes folded to
 *   Latin, everything but letters and digits dropped, then the NIP-05
 *   skeleton: `0`→o, `1`/`l`→i, `rn`→m, runs of one letter once). A name
 *   whose key is shorter than {@see MIN_NAME_LENGTH} characters never matches.
 * - **picture**: the two picture URLs are byte-for-byte equal.
 *
 * Excluded: the account itself and every account of its person (an active
 * link either way, {@see AccountLinks}). Only the account WITHOUT precedence
 * is held: a member or an account on a member's opponent list ("protected")
 * has precedence over one that is neither; between two of the same kind the
 * one known to the league first (`users.created_at`, then the id). So the
 * original keeps playing when a look-alike appears, and its own profile
 * refresh never holds it.
 *
 * A hit writes one {@see ImpersonationHold} per matched account. No new hold
 * is raised while one for the same pair is open, nor after a release for the
 * same name skeleton and picture URL: only a changed name or picture that
 * matches anew raises one. A link or a bar ends the hold; should the same
 * match come back after the bar ended or the link was undone, it is raised
 * again.
 *
 * Behind the switch `esports.fair_play.impersonation.enabled` (off by
 * default): off, nothing here runs a query, writes or holds anybody.
 */
final class Impersonation
{
    /** The shortest name key that is compared at all: `al`, `ed` or an emoji-only name match nobody. */
    public const MIN_NAME_LENGTH = 3;

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

        return $name === '' ? '' : Nip05Names::skeleton($name);
    }

    /** Whether this name key is long enough to be compared. */
    public static function comparable(string $key): bool
    {
        return mb_strlen($key) >= self::MIN_NAME_LENGTH;
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
                $matches[$other->pubkey] = ['user' => $other, 'name' => $name, 'picture' => $same];
            }
        }

        if ($matches === []) {
            return [];
        }

        $protected = self::protected([$user, ...array_column($matches, 'user')]);
        $matches = array_filter($matches, fn (array $match): bool => self::precedes($match['user'], $user, $protected));

        if ($matches === []) {
            return [];
        }

        return DB::transaction(function () use ($user, $matches, $key, $picture): array {
            // One check at a time for this account: the holds below are read under the lock.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $existing = ImpersonationHold::query()->where('pubkey', $user->pubkey)->whereIn('matched_pubkey', array_keys($matches))
                ->where(fn ($query) => $query->whereNull('decided_at')->orWhere('decision', ImpersonationHold::RELEASED))
                ->get()->groupBy('matched_pubkey');
            $written = [];

            foreach ($matches as $matchedPubkey => $match) {
                $rows = $existing->get($matchedPubkey) ?? collect();

                if ($rows->contains(fn (ImpersonationHold $hold): bool => $hold->isOpen())) {
                    continue;
                }

                // Released before: raised again only for what matches now and was not released for this very value.
                $nameReleased = ! $match['name'] || $rows->contains(fn (ImpersonationHold $hold): bool => $hold->matchesName() && $hold->name_skeleton === $key);
                $pictureReleased = ! $match['picture'] || $rows->contains(fn (ImpersonationHold $hold): bool => $hold->matchesPicture() && $hold->picture === $picture);

                if ($nameReleased && $pictureReleased) {
                    continue;
                }

                $written[] = ImpersonationHold::query()->create([
                    'user_id' => $user->id,
                    'pubkey' => $user->pubkey,
                    'matched_user_id' => $match['user']->id,
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
     * The protected among these accounts: Verein members, and accounts a
     * member lists on the league opponent list.
     *
     * @param  list<User>  $users
     * @return array<string, true>
     */
    private static function protected(array $users): array
    {
        $protected = [];

        foreach ($users as $user) {
            if ($user->is_member) {
                $protected[$user->pubkey] = true;
            }
        }

        $lists = OpponentLists::forLeague();

        if ($lists === null) {
            return $protected;
        }

        $wanted = array_fill_keys(array_map(fn (User $user): string => $user->pubkey, $users), true);
        $members = User::query()->where('is_member', true)->select('pubkey');

        foreach ($lists->newestEntriesOf($members) as $entries) {
            foreach ($entries as $entry) {
                if (isset($wanted[$entry])) {
                    $protected[$entry] = true;
                }
            }
        }

        return $protected;
    }

    /**
     * Whether `$other` has precedence over `$user`: protected over not
     * protected, else known to the league first.
     *
     * @param  array<string, true>  $protected
     */
    private static function precedes(User $other, User $user, array $protected): bool
    {
        $otherProtected = isset($protected[$other->pubkey]);
        $userProtected = isset($protected[$user->pubkey]);

        if ($otherProtected !== $userProtected) {
            return $otherProtected;
        }

        return [$other->created_at?->getTimestamp() ?? PHP_INT_MAX, $other->id] < [$user->created_at?->getTimestamp() ?? PHP_INT_MAX, $user->id];
    }
}
