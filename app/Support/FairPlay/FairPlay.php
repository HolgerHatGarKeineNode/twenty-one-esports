<?php

namespace App\Support\FairPlay;

use App\Models\AccountLink;
use App\Models\FalseReport;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\LeagueTime;
use Carbon\CarbonImmutable;

/**
 * Who is barred from rated play by the fair-play rules (P41), read live
 * wherever rated play is offered or pinned (RatedTrustGate, RatedChess):
 *
 * - a **linked** account: an admin linked it to the main account of the same
 *   person ({@see AccountLinks}); barred until it is unlinked. It also wins
 *   no prize (PayoutPlan skips it).
 * - a **locked** player: `fair_play.false_reports` confirmed false reports
 *   ({@see FalseReport}) within `fair_play.window_days` days of each other
 *   bar the player for `fair_play.lock_days` days from the last of them.
 *
 * Only new rated play is refused: a match accepted before keeps its pinned
 * gate ("Nothing after the accept undoes the gate"). Results between two
 * accounts of one person are voided by the link itself and rate nothing
 * afterwards ({@see samePerson()}, RatingService).
 *
 * @phpstan-type Bar array{kind: 'linked'|'locked', until: CarbonImmutable|null, main: string|null}
 */
final class FairPlay
{
    /**
     * The barred pubkeys among these, with why and until when (a link has
     * no end).
     *
     * @param  list<string>  $pubkeys
     * @return array<string, Bar>
     */
    public static function barred(array $pubkeys): array
    {
        $pubkeys = array_values(array_unique(array_filter($pubkeys, fn (string $pubkey): bool => $pubkey !== '')));

        if ($pubkeys === []) {
            return [];
        }

        $barred = [];

        foreach (AccountLink::query()->active()->whereIn('linked_pubkey', $pubkeys)->get(['linked_pubkey', 'main_pubkey']) as $link) {
            $barred[$link->linked_pubkey] = ['kind' => 'linked', 'until' => null, 'main' => $link->main_pubkey];
        }

        foreach (self::locks(array_values(array_diff($pubkeys, array_keys($barred)))) as $pubkey => $until) {
            $barred[$pubkey] = ['kind' => 'locked', 'until' => $until, 'main' => null];
        }

        return $barred;
    }

    public static function isBarred(string $pubkey): bool
    {
        return self::barred([$pubkey]) !== [];
    }

    /** Whether this account is linked to another (main) account now. */
    public static function isLinked(string $pubkey): bool
    {
        return $pubkey !== '' && AccountLink::query()->active()->where('linked_pubkey', $pubkey)->exists();
    }

    /**
     * The end of this player's lock after confirmed false reports, or null
     * when not locked.
     */
    public static function lockedUntil(string $pubkey): ?CarbonImmutable
    {
        return self::locks([$pubkey])[$pubkey] ?? null;
    }

    /**
     * Why this player cannot play rated, in plain words with the end time,
     * or null when nothing bars him. Addressed to the viewer when it is his
     * own account.
     */
    public static function message(string $pubkey, ?User $viewer = null): ?string
    {
        $bar = self::barred([$pubkey])[$pubkey] ?? null;

        if ($bar === null) {
            return null;
        }

        $viewer ??= auth()->user() instanceof User ? auth()->user() : null;
        $own = $viewer !== null && $viewer->pubkey === $pubkey;
        $name = User::query()->where('pubkey', $pubkey)->first()?->displayName() ?? __('A player');

        if ($bar['kind'] === 'linked') {
            $main = User::query()->where('pubkey', (string) $bar['main'])->first()?->displayName() ?? __('another account');

            return $own
                ? __('An admin linked this account to your main account :main. Only the main account plays rated matches and wins prizes.', ['main' => $main])
                : __(':name is a second account of another player and plays no rated matches.', ['name' => $name]);
        }

        $until = LeagueTime::stamp($bar['until'] ?? now());

        return $own
            ? __('You cannot play rated until :time: :count false result reports were confirmed within :days days. Casual play stays open.', [
                'time' => $until,
                'count' => self::threshold(),
                'days' => self::windowDays(),
            ])
            : __(':name cannot play rated until :time after confirmed false result reports.', ['name' => $name, 'time' => $until]);
    }

    /**
     * Whether the two sides of a result hold two accounts of one person
     * (an active link between them, or the same account on both sides).
     *
     * @param  list<int>  $a  user ids of one side
     * @param  list<int>  $b  user ids of the other side
     */
    public static function samePerson(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        $persons = self::persons([...$a, ...$b]);
        $of = fn (array $ids): array => array_map(fn (int $id): int => $persons[$id] ?? $id, $ids);

        return array_intersect($of($a), $of($b)) !== [];
    }

    /**
     * The players of each side of a series as far as the league knows them:
     * a roster side's players, who played as the room has it, and the
     * counted report's (or admin decision's) roster.
     *
     * @return array{challenger: list<int>, challenged: list<int>}
     */
    public static function seriesSides(SeriesMatch $match): array
    {
        $sides = ['challenger' => [], 'challenged' => []];

        foreach (SeriesMatch::SIDES as $side) {
            $sides[$side] = [...$match->rosterSide($side), ...array_map(intval(...), $match->rosters[$side] ?? [])];
        }

        foreach ($match->countedRoster() as $entry) {
            if (isset($sides[$entry['side']])) {
                $sides[$entry['side']][] = (int) $entry['user_id'];
            }
        }

        return ['challenger' => array_values(array_unique($sides['challenger'])), 'challenged' => array_values(array_unique($sides['challenged']))];
    }

    /**
     * Each user id's person: the main account's id for a linked account,
     * the id itself otherwise.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public static function persons(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        $persons = array_combine($userIds, $userIds);

        foreach (AccountLink::query()->active()->where(fn ($query) => $query->whereIn('linked_user_id', $userIds)->orWhereIn('main_user_id', $userIds))
            ->whereNotNull('main_user_id')->get(['main_user_id', 'linked_user_id']) as $link) {
            if ($link->linked_user_id !== null && isset($persons[$link->linked_user_id])) {
                $persons[$link->linked_user_id] = (int) $link->main_user_id;
            }
        }

        return $persons;
    }

    public static function threshold(): int
    {
        return max(1, (int) config('esports.fair_play.false_reports', 2));
    }

    public static function windowDays(): int
    {
        return max(1, (int) config('esports.fair_play.window_days', 30));
    }

    public static function lockDays(): int
    {
        return max(1, (int) config('esports.fair_play.lock_days', 7));
    }

    /**
     * The running locks of these players: the newest `threshold()` false
     * reports lie within `windowDays()` of each other and the newest is less
     * than `lockDays()` old. Only reports of the last window + lock days can
     * start a lock that still runs.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, CarbonImmutable>
     */
    private static function locks(array $pubkeys): array
    {
        if ($pubkeys === []) {
            return [];
        }

        $threshold = self::threshold();
        $reports = FalseReport::query()->whereIn('pubkey', $pubkeys)
            ->where('created_at', '>=', now()->subDays(self::windowDays() + self::lockDays()))
            ->orderByDesc('created_at')->orderByDesc('id')->get(['pubkey', 'created_at'])->groupBy('pubkey');
        $locks = [];

        foreach ($reports as $pubkey => $rows) {
            $newest = $rows->take($threshold);

            if ($newest->count() < $threshold) {
                continue;
            }

            $last = CarbonImmutable::instance($newest->first()->created_at);
            $first = CarbonImmutable::instance($newest->last()->created_at);
            $until = $last->addDays(self::lockDays());

            if ($first->greaterThanOrEqualTo($last->subDays(self::windowDays())) && $until->isFuture()) {
                $locks[(string) $pubkey] = $until;
            }
        }

        return $locks;
    }
}
