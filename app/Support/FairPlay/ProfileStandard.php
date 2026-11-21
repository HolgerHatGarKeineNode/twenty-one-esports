<?php

namespace App\Support\FairPlay;

use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Nostr\ProfileCache;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The profile standard of rated play (plan "Profile standard and
 * impersonation", P1; user decision 2026-11-21): on top of the trust gate,
 * an account plays rated only with three hard marks, all of them shown to
 * the player because each costs time or a real contact:
 *
 * - `known`: the account is known to the league for `known_days` days since
 *   its first login (`users.created_at`, written once by the Nostr login;
 *   never the Nostr `created_at`, which the sender sets freely);
 * - `nip05`: a well-formed NIP-05 address in the cached profile (`nip05`,
 *   which {@see ProfileCache} stores only when it reads as name@domain) or
 *   the league's own name (`nip05_name`); either counts. Whether the address
 *   resolves is deliberately not asked (user 2026-11-21: no list of verified
 *   addresses to keep, the standard would become too strict to maintain);
 * - `profile`: a non-empty `name` and `picture` of the cached kind-0 profile.
 *
 * Verein members are exempt from all three (paid membership is a hard mark
 * of its own, user 2026-11-21), and so are players who already played rated
 * in the live season before the switch-on date ({@see exempt()}). The standard is read by
 * {@see FairPlay::barred()} as kind `standard`, so like every other kind it
 * refuses only new rated play: a match accepted before keeps its pinned gate.
 *
 * Behind the switch `esports.fair_play.profile_standard.enabled` (off by
 * default): off, nothing here is read and rated play is what it was.
 */
final class ProfileStandard
{
    public const KNOWN = 'known';

    public const NIP05 = 'nip05';

    public const PROFILE = 'profile';

    public static function enabled(): bool
    {
        return (bool) config('esports.fair_play.profile_standard.enabled');
    }

    public static function knownDays(): int
    {
        return max(1, (int) config('esports.fair_play.profile_standard.known_days'));
    }

    /**
     * The switch-on date (`esports.fair_play.profile_standard.since`, read in
     * the league's zone and returned in UTC like the columns it is compared
     * with), or null when none is set.
     */
    public static function since(): ?CarbonImmutable
    {
        $since = config('esports.fair_play.profile_standard.since');

        return is_string($since) && trim($since) !== '' ? CarbonImmutable::parse($since, LeagueTime::zone())->utc() : null;
    }

    /**
     * The hard marks this account lacks, in the order the player can fix
     * them, whatever the switch and the exemption say.
     *
     * @return list<'known'|'nip05'|'profile'>
     */
    public static function missing(User $user): array
    {
        $missing = [];

        if ($user->created_at === null || self::knownAt($user)->isFuture()) {
            $missing[] = self::KNOWN;
        }

        if (trim((string) $user->nip05) === '' && trim((string) $user->nip05_name) === '') {
            $missing[] = self::NIP05;
        }

        if (trim((string) $user->name) === '' || trim((string) $user->picture) === '') {
            $missing[] = self::PROFILE;
        }

        return $missing;
    }

    /** When the account reaches `known_days` days since its first login. */
    public static function knownAt(User $user): CarbonImmutable
    {
        return CarbonImmutable::instance($user->created_at ?? now())->addDays(self::knownDays());
    }

    public static function isExempt(User $user): bool
    {
        return in_array($user->pubkey, self::exempt([$user->pubkey]), true);
    }

    /**
     * The players among these below the standard now, with the marks they
     * lack and until when (`until`: the day `known` is reached when that is
     * the only mark missing, otherwise null). Empty while the switch is off.
     * A pubkey without an account is not known to the league and lacks every
     * mark (fail closed). Three queries for any number of players.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, array{missing: non-empty-list<'known'|'nip05'|'profile'>, until: CarbonImmutable|null}>
     */
    public static function below(array $pubkeys): array
    {
        if (! self::enabled() || $pubkeys === []) {
            return [];
        }

        $users = User::query()->whereIn('pubkey', $pubkeys)
            ->get(['id', 'pubkey', 'name', 'picture', 'nip05', 'nip05_name', 'created_at'])->keyBy('pubkey');
        $below = [];

        foreach ($pubkeys as $pubkey) {
            $user = $users[$pubkey] ?? null;
            $missing = $user === null ? [self::KNOWN, self::NIP05, self::PROFILE] : self::missing($user);

            if ($missing !== []) {
                $below[$pubkey] = ['missing' => $missing, 'until' => $user !== null && $missing === [self::KNOWN] ? self::knownAt($user) : null];
            }
        }

        return array_diff_key($below, array_flip(self::exempt(array_keys($below))));
    }

    /**
     * The exempt players among these: Verein members (`users.is_member`),
     * and who played a rated match in the live season before the switch-on
     * date, read from the two records of a rated result, never from a stored
     * flag:
     *
     * - a league attestation (`season_attestations`, every rated result of
     *   every game, Hyperbitcoinization tables and score windows included)
     *   whose candidate names the player as a winner or a loser, attested
     *   before the date;
     * - a live rating change on the player's own rated rating row of the live
     *   season (`ratings.pool` rated, `ratings.season` its slug) written
     *   before the date: a rated draw, which is attested without a candidate.
     *
     * Without a switch-on date the cut-off is now: whoever has played rated in
     * the live season so far is exempt, so the standard bars only accounts that
     * have not played rated yet (set the date at go-live to freeze that set).
     * Outside a season only members are exempt.
     *
     * @param  list<string>  $pubkeys
     * @return list<string>
     */
    public static function exempt(array $pubkeys): array
    {
        if ($pubkeys === []) {
            return [];
        }

        $exempt = array_fill_keys(array_map(strval(...), User::query()->whereIn('pubkey', $pubkeys)->where('is_member', true)->pluck('pubkey')->all()), true);
        $season = Seasons::live();

        if ($season === null) {
            return array_values(array_intersect($pubkeys, array_keys($exempt)));
        }

        $cutoff = self::since() ?? CarbonImmutable::now();

        $candidates = SeasonAttestation::query()->where('season_id', $season->id)->where('attested_at', '<', $cutoff)->whereNotNull('candidate')
            ->where(function (Builder $query) use ($pubkeys): void {
                foreach ($pubkeys as $pubkey) {
                    $query->orWhereJsonContains('candidate->winners', $pubkey)->orWhereJsonContains('candidate->losers', $pubkey);
                }
            })
            ->pluck('candidate');

        foreach ($candidates as $candidate) {
            foreach ([...(array) ($candidate['winners'] ?? []), ...(array) ($candidate['losers'] ?? [])] as $pubkey) {
                $exempt[(string) $pubkey] = true;
            }
        }

        $rest = array_values(array_diff($pubkeys, array_keys($exempt)));

        if ($rest !== []) {
            $rated = RatingChange::query()->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
                ->join('users', 'users.id', '=', 'ratings.user_id')
                ->where('ratings.pool', Rating::RATED)->where('ratings.season', $season->slug)
                ->whereIn('users.pubkey', $rest)->where('rating_changes.created_at', '<', $cutoff)
                ->distinct()->pluck('users.pubkey');

            foreach ($rated as $pubkey) {
                $exempt[(string) $pubkey] = true;
            }
        }

        return array_values(array_intersect($pubkeys, array_keys($exempt)));
    }

    /**
     * The steps this player still has to take before rated play opens, or
     * none while the switch is off, the standard is met or the player is
     * exempt ({@see below()}).
     *
     * @return list<array{key: 'known'|'nip05'|'profile', text: string, url: string|null}>
     */
    public static function stepsFor(User $user): array
    {
        $below = self::below([$user->pubkey])[$user->pubkey] ?? null;

        return $below === null ? [] : self::steps($user, $below['missing']);
    }

    /**
     * What the player still has to do, at most three steps and only the
     * missing ones. `url` points to the page that fixes it, when the league
     * has one (the league's own NIP-05 name).
     *
     * @param  list<'known'|'nip05'|'profile'>  $missing
     * @return list<array{key: 'known'|'nip05'|'profile', text: string, url: string|null}>
     */
    public static function steps(User $user, array $missing): array
    {
        $steps = [];

        foreach ($missing as $mark) {
            $steps[] = match ($mark) {
                self::KNOWN => ['key' => $mark, 'url' => null, 'text' => __('Wait until :time: an account plays rated from :days days after its first login here.', [
                    'time' => LeagueTime::stamp(self::knownAt($user)), 'days' => self::knownDays(),
                ])],
                self::NIP05 => ['key' => $mark, 'url' => route('settings.nip05'), 'text' => __('Set a NIP-05 address, for example the league\'s own name under Settings › Nostr address.')],
                self::PROFILE => ['key' => $mark, 'url' => null, 'text' => __('Set a name and a profile picture in your Nostr client.')],
            };
        }

        return $steps;
    }
}
