<?php

namespace App\Support\Nostr;

use App\Http\Controllers\NostrJsonController;
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
 * - reserved: `esports.nip05.reserved`, the league's own NIP-05 name and the
 *   pool's Lightning address name, and every name an admin revoked while
 *   that account exists;
 * - a change (a new name, or a claim after a release or a revocation) at most
 *   once in `esports.nip05.change_days`; the first claim is free;
 * - deleting the account releases the name (it lives on the user row).
 *
 * The league does not write the name into the player's Nostr profile: the
 * profile (kind 0) is theirs, and they set `nip05` in their own client.
 */
final class Nip05Names
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

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

        if (in_array($name, self::reserved(), true) || User::query()->where('nip05_revoked_name', $name)->exists()) {
            return __('This name is reserved.');
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
     * Claim or change the name. Returns the refusal, or null on success.
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
     * Give the name up. The next claim counts as a change.
     */
    public function release(User $user): void
    {
        if ($user->nip05_name === null) {
            return;
        }

        $user->forceFill(['nip05_name' => null, 'nip05_changed_at' => now()])->save();
    }

    /**
     * An admin takes a name back: the player loses it, nobody can claim it
     * while the account exists, and the player picks another one after the
     * change limit.
     */
    public function revoke(User $user): void
    {
        if ($user->nip05_name === null) {
            return;
        }

        $user->forceFill([
            'nip05_revoked_name' => $user->nip05_name,
            'nip05_name' => null,
            'nip05_revoked_at' => now(),
            'nip05_changed_at' => now(),
        ])->save();
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
}
