<?php

namespace App\Support\Nostr;

use App\Http\Controllers\NostrJsonController;
use App\Models\Nip05Hold;
use App\Models\User;
use App\Support\PreSeason;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NIP-05 names on the league's own domain (P47): a player may claim
 * `name@<app host>`, which /.well-known/nostr.json then answers with their
 * pubkey ({@see NostrJsonController}). Opt-in in the
 * settings; nothing is served for a player who did not claim a name.
 *
 * Rules:
 * - lowercase a-z, 0-9, `.`, `_`, `-` (NIP-05 allows exactly these in the
 *   local part), starting with a letter or digit, `esports.nip05.min_length`
 *   to `max_length` characters; input is lowercased first;
 * - unique (the column's unique index decides a race);
 * - reserved ({@see isReserved()}): `esports.nip05.reserved`, the league's own
 *   NIP-05 name and the pool's Lightning address name, also as a part of a
 *   name (`support-team`, `league.admin`), as a longer word
 *   (`administrators`) and in look-alike spelling (`0` for `o`, `rn` for `m`;
 *   P47 security audit F4);
 * - held ({@see Nip05Hold}, audit F2): a name an admin revoked stays held
 *   until an admin lifts the hold, whatever happens to the account; a name
 *   given up (released, changed, gone with the account) is held for
 *   `esports.nip05.change_days` against every other key, and only the key
 *   that held it may take it back meanwhile;
 * - a change (a new name, or a claim after a release or a revocation) at most
 *   once in `esports.nip05.change_days`; the first claim is free.
 *
 * The league does not write the name into the player's Nostr profile: the
 * profile (kind 0) is theirs, and they set `nip05` in their own client.
 */
final class Nip05Names
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /** A reserved word this long or longer is also refused as the start of a part (`administrators`, `supporter`). */
    public const PREFIX_MIN = 5;

    public static function domain(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }

    public static function address(User $user): ?string
    {
        return $user->nip05_name === null ? null : $user->nip05_name.'@'.self::domain();
    }

    /**
     * Names nobody claims: the configured list, the league's own NIP-05 name
     * and the pool's Lightning address name.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        $league = Str::before((string) config('twentyone.profile.nip05'), '@');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $name): string => strtolower((string) $name),
            [...(array) config('esports.nip05.reserved', []), $league, config('esports.wallet.lnurl_username')],
        ), fn (string $name): bool => $name !== '')));
    }

    /**
     * A name as it reads: look-alike characters folded (`0`→o, `1`→l, `3`→e,
     * `5`→s, `rn`→m), so `supp0rt` and `adrnin` read as the words they imitate.
     */
    public static function skeleton(string $name): string
    {
        return str_replace('rn', 'm', strtr(strtolower($name), ['0' => 'o', '1' => 'l', '3' => 'e', '5' => 's']));
    }

    /**
     * Whether a name is, contains or imitates a reserved word: the whole name
     * without separators, and every part between `.`, `_` and `-`, each
     * folded ({@see skeleton()}), against every reserved word folded the same
     * way; a part also when it only adds a plural `s` or starts with a
     * reserved word of PREFIX_MIN letters or more.
     */
    public static function isReserved(string $name): bool
    {
        $words = array_values(array_unique(array_map(self::skeleton(...), self::reserved())));
        $parts = array_filter(preg_split('/[._-]+/', self::skeleton($name)) ?: [], fn (string $part): bool => $part !== '');
        $parts[] = (string) preg_replace('/[._-]+/', '', self::skeleton($name));

        foreach ($parts as $part) {
            foreach ($words as $word) {
                if ($part === $word || $part === $word.'s' || (strlen($word) >= self::PREFIX_MIN && str_starts_with($part, $word))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The hold in force on a name against this key, or null: every revoked
     * hold, and a released one unless this key held the name.
     */
    public function holdOn(string $name, ?string $pubkey): ?Nip05Hold
    {
        return Nip05Hold::query()->active()->where('name', self::normalize($name))
            ->where(fn ($query) => $query->where('reason', Nip05Hold::REVOKED)
                ->orWhere(fn ($query) => $query->where('reason', Nip05Hold::RELEASED)->where(fn ($query) => $query->whereNull('pubkey')->orWhere('pubkey', '!=', (string) $pubkey))))
            ->orderByRaw("case when reason = 'revoked' then 0 else 1 end")->latest('id')->first();
    }

    /**
     * Why `$name` cannot be this player's, or null when it can (the unique
     * index still decides a race at the claim).
     */
    public function problem(string $name, User $user): ?string
    {
        $name = self::normalize($name);
        $min = (int) config('esports.nip05.min_length', 3);
        $max = (int) config('esports.nip05.max_length', 30);

        if (strlen($name) < $min || strlen($name) > $max) {
            return __('A name has :min to :max characters.', ['min' => $min, 'max' => $max]);
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            return __('Use only a to z, 0 to 9, dot, underscore and hyphen, starting with a letter or a digit.');
        }

        if ($name === $user->nip05_name) {
            return __('That is your name already.');
        }

        if (self::isReserved($name)) {
            return __('This name is reserved.');
        }

        $hold = $this->holdOn($name, $user->pubkey);

        if ($hold !== null) {
            return $hold->reason === Nip05Hold::REVOKED
                ? __('This name is reserved.')
                : __('This name was given up recently and is held until :date.', ['date' => $hold->held_until?->copy()->timezone(PreSeason::timezoneFor($user))->translatedFormat('j M Y') ?? '']);
        }

        if (User::query()->where('nip05_name', $name)->whereKeyNot($user->id)->exists()) {
            return __('This name is taken.');
        }

        $next = $this->nextChangeAt($user);

        if ($next !== null && $next->isFuture()) {
            return __('You can pick a new name from :date on.', ['date' => $next->copy()->timezone(PreSeason::timezoneFor($user))->translatedFormat('j M Y, H:i')]);
        }

        return null;
    }

    /**
     * When this player may change their name next; null: now (no change so
     * far, or the last one lies far enough back).
     */
    public function nextChangeAt(User $user): ?CarbonInterface
    {
        if ($user->nip05_changed_at === null) {
            return null;
        }

        $next = $user->nip05_changed_at->copy()->addDays((int) config('esports.nip05.change_days', 30));

        return $next->isFuture() ? $next : null;
    }

    /**
     * Claim or change the name. Returns the refusal, or null on success. A
     * changed name is held for the change period like a released one.
     */
    public function claim(User $user, string $name): ?string
    {
        $name = self::normalize($name);

        try {
            $problem = DB::transaction(function () use ($user, $name): ?string {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $problem = $this->problem($name, $locked);

                if ($problem !== null) {
                    return $problem;
                }

                if ($locked->nip05_name !== null) {
                    $this->holdReleased($locked->nip05_name, $locked);
                }

                $locked->forceFill(['nip05_name' => $name, 'nip05_changed_at' => now()])->save();

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            return __('This name is taken.');
        }

        $user->refresh();

        return $problem;
    }

    /**
     * Give the name up: held for the change period against every other key;
     * the next claim counts as a change.
     */
    public function release(User $user): void
    {
        if ($user->nip05_name === null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $this->holdReleased((string) $user->nip05_name, $user);
            $user->forceFill(['nip05_name' => null, 'nip05_changed_at' => now()])->save();
        });
    }

    /**
     * The account is being deleted: its name is held like a released one, so
     * the key can take it back after logging in again and nobody else can
     * for the change period (called from the User model's `deleting` event).
     */
    public function releaseForDeletion(User $user): void
    {
        if ($user->nip05_name !== null) {
            $this->holdReleased($user->nip05_name, $user);
        }
    }

    /**
     * An admin takes a name back: the player loses it, a hold keeps anybody
     * (that key included, after any account deletion) from claiming it until
     * an admin lifts the hold, and the player picks another name after the
     * change limit. Every revocation is its own hold; none replaces another.
     */
    public function revoke(User $user, ?User $admin = null): void
    {
        if ($user->nip05_name === null) {
            return;
        }

        DB::transaction(function () use ($user, $admin): void {
            Nip05Hold::query()->create([
                'name' => $user->nip05_name,
                'reason' => Nip05Hold::REVOKED,
                'pubkey' => $user->pubkey,
                'user_id' => $user->id,
                'held_until' => null,
                'created_by_id' => $admin?->id,
            ]);

            $user->forceFill([
                'nip05_revoked_name' => $user->nip05_name,
                'nip05_name' => null,
                'nip05_revoked_at' => now(),
                'nip05_changed_at' => now(),
            ])->save();
        });
    }

    /**
     * An admin ends a hold early.
     */
    public function lift(Nip05Hold $hold, User $admin): void
    {
        Nip05Hold::query()->whereKey($hold->id)->whereNull('lifted_at')->update(['lifted_at' => now(), 'lifted_by_id' => $admin->id]);
    }

    /**
     * The player behind `name@<domain>`, or null.
     */
    public function lookup(string $name): ?User
    {
        $name = self::normalize($name);

        if (preg_match(self::PATTERN, $name) !== 1 || strlen($name) > (int) config('esports.nip05.max_length', 30)) {
            return null;
        }

        return User::query()->where('nip05_name', $name)->first();
    }

    private function holdReleased(string $name, User $user): void
    {
        Nip05Hold::query()->create([
            'name' => $name,
            'reason' => Nip05Hold::RELEASED,
            'pubkey' => $user->pubkey,
            'user_id' => $user->exists ? $user->id : null,
            'held_until' => now()->addDays((int) config('esports.nip05.change_days', 30)),
        ]);
    }
}
