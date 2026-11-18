<?php

namespace App\Support\FairPlay;

use App\Models\AccountLink;
use App\Models\FairPlayBar;
use App\Models\FalseReport;
use App\Models\HyperSeat;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\SeasonChain\Seasons;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Who is barred from rated play by the fair-play rules (P41), read live
 * wherever rated play is offered or pinned (RatedTrustGate, RatedChess):
 *
 * - a **linked** account: an admin linked it to the main account of the same
 *   person ({@see AccountLinks}); barred until it is unlinked. It also wins
 *   no prize (PayoutPlan skips it).
 * - a player an admin **barred** by hand ({@see Bars}), until the end the
 *   admin set or until the bar is lifted. The reason is internal: the player
 *   reads only that the league locked rated play and until when.
 * - a **locked** player: `fair_play.false_reports` confirmed false reports
 *   ({@see FalseReport}) within `fair_play.window_days` days of each other
 *   bar the player for `fair_play.lock_days` days from the last of them.
 * - a player who **forfeits** rated Hyperbitcoinization matches (leaves, or
 *   lets the turns run out: `HyperSeat::TAKEOVER_FORFEIT`): `fair_play.forfeits`
 *   of them within `fair_play.forfeit_window_days` days bar the player from
 *   rated play in every game for `fair_play.forfeit_lock_days` days from the
 *   last one. Only forfeits since the live season's Block 0 count.
 *
 * Only new rated play is refused: a match accepted before keeps its pinned
 * gate ("Nothing after the accept undoes the gate"). Results between two
 * accounts of one person are voided by the link itself and rate nothing
 * afterwards ({@see samePerson()}, RatingService).
 *
 * @phpstan-type Bar array{kind: 'linked'|'barred'|'locked'|'forfeits', until: CarbonImmutable|null, main: string|null}
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

        // The bar that runs longest decides the end (ascending, so the last one written wins).
        foreach (FairPlayBar::query()->running()->whereIn('pubkey', array_values(array_diff($pubkeys, array_keys($barred))))->orderBy('until')->get(['pubkey', 'until']) as $bar) {
            $barred[$bar->pubkey] = ['kind' => 'barred', 'until' => CarbonImmutable::instance($bar->until), 'main' => null];
        }

        foreach (self::locks(array_values(array_diff($pubkeys, array_keys($barred)))) as $pubkey => $until) {
            $barred[$pubkey] = ['kind' => 'locked', 'until' => $until, 'main' => null];
        }

        foreach (self::forfeitLocks(array_values(array_diff($pubkeys, array_keys($barred)))) as $pubkey => $until) {
            $barred[$pubkey] = ['kind' => 'forfeits', 'until' => $until, 'main' => null];
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

        // A bar set by an admin: the neutral sentence, never the internal reason.
        if ($bar['kind'] === 'barred') {
            return $own
                ? __('The league has locked rated play for your account until :time. Casual play stays open.', ['time' => $until])
                : __(':name cannot play rated until :time.', ['name' => $name, 'time' => $until]);
        }

        if ($bar['kind'] === 'forfeits') {
            return $own
                ? __('You cannot play rated until :time: you left :count rated Hyperbitcoinization matches within :days days. Casual play stays open.', [
                    'time' => $until,
                    'count' => self::forfeitThreshold(),
                    'days' => self::forfeitWindowDays(),
                ])
                : __(':name cannot play rated until :time after leaving rated matches.', ['name' => $name, 'time' => $until]);
        }

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
        return max(1, (int) LeagueSettings::get('esports.fair_play.false_reports'));
    }

    public static function windowDays(): int
    {
        return max(1, (int) LeagueSettings::get('esports.fair_play.window_days'));
    }

    public static function lockDays(): int
    {
        return max(1, (int) LeagueSettings::get('esports.fair_play.lock_days'));
    }

    public static function forfeitThreshold(): int
    {
        return max(1, (int) LeagueSettings::get('esports.fair_play.forfeits'));
    }

    public static function forfeitWindowDays(): int
    {
        return max(1, (int) LeagueSettings::get('esports.fair_play.forfeit_window_days'));
    }

    public static function forfeitLockDays(): int
    {
        return max(1, (int) LeagueSettings::get('esports.fair_play.forfeit_lock_days'));
    }

    /**
     * How many rated Hyperbitcoinization matches this player forfeited within
     * the last `forfeitWindowDays()` days (since Block 0 of the live season):
     * one more of them reaches `forfeitThreshold()` when this is one below it.
     */
    public static function forfeitsInWindow(User $user): int
    {
        $since = self::forfeitsSince(self::forfeitWindowDays());

        return $since === null ? 0 : self::forfeits([$user->id], $since)->count();
    }

    /**
     * How many rated Hyperbitcoinization matches each of these players
     * forfeited since `$since` (the checking view passes Block 0 of the live
     * season): the same seats {@see forfeitsInWindow()} counts, for any
     * number of players in one query. Players without a forfeit are absent.
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user id => forfeits
     */
    public static function forfeitCounts(array $userIds, CarbonImmutable $since): array
    {
        $counts = [];

        foreach (self::forfeits($userIds, $since) as $row) {
            $counts[(int) $row->user_id] = ($counts[(int) $row->user_id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * What leaving a running rated Hyperbitcoinization match would cost this
     * player now, in short lines for the confirmation: `count` is the number
     * this forfeit would have within the window, `locks` whether it reaches
     * `forfeitThreshold()` and so locks rated play.
     *
     * @return array{count: int, limit: int, days: int, lock_days: int, locks: bool, lines: list<string>}
     */
    public static function forfeitWarning(User $user): array
    {
        $count = self::forfeitsInWindow($user) + 1;
        $limit = self::forfeitThreshold();
        $days = self::forfeitWindowDays();
        $lock = self::forfeitLockDays();

        return [
            'count' => $count,
            'limit' => $limit,
            'days' => $days,
            'lock_days' => $lock,
            'locks' => $count >= $limit,
            'lines' => [
                __('If you leave, nobody at this table mines a season block.'),
                __('The match counts as a loss for you.'),
                __('It would be rated match no. :count you left within :days days. The limit is :limit.', ['count' => $count, 'days' => $days, 'limit' => $limit]),
                match (true) {
                    $count >= $limit => __('This one locks rated play in every game for :lock days.', ['lock' => $lock]),
                    $count === $limit - 1 => __('The next one locks rated play in every game for :lock days.', ['lock' => $lock]),
                    default => __('From no. :limit on, rated play is locked in every game for :lock days.', ['limit' => $limit, 'lock' => $lock]),
                },
            ],
        ];
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

    /**
     * The running locks after forfeited rated Hyperbitcoinization matches,
     * in the shape of {@see locks()}: the newest `forfeitThreshold()`
     * forfeits lie within `forfeitWindowDays()` of each other and the newest
     * is less than `forfeitLockDays()` old. A bounded number of queries for
     * any number of players.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, CarbonImmutable>
     */
    private static function forfeitLocks(array $pubkeys): array
    {
        $since = $pubkeys === [] ? null : self::forfeitsSince(self::forfeitWindowDays() + self::forfeitLockDays());

        if ($since === null) {
            return [];
        }

        /** @var array<int, string> $users */
        $users = User::query()->whereIn('pubkey', $pubkeys)->pluck('pubkey', 'id')->all();
        $threshold = self::forfeitThreshold();
        $locks = [];

        foreach (self::forfeits(array_keys($users), $since)->groupBy('user_id') as $userId => $rows) {
            $newest = $rows->take($threshold);

            if ($newest->count() < $threshold) {
                continue;
            }

            $last = CarbonImmutable::parse((string) $newest->first()?->left_at);
            $first = CarbonImmutable::parse((string) $newest->last()?->left_at);
            $until = $last->addDays(self::forfeitLockDays());

            if ($first->greaterThanOrEqualTo($last->subDays(self::forfeitWindowDays())) && $until->isFuture()) {
                $locks[$users[(int) $userId]] = $until;
            }
        }

        return $locks;
    }

    /**
     * From when forfeits count looking `$days` back: never before Block 0 of
     * the live season (nothing before the release locks anyone); null
     * outside a season, when no forfeit counts.
     */
    private static function forfeitsSince(int $days): ?CarbonImmutable
    {
        $genesis = Seasons::live()?->genesis_at;

        if ($genesis === null) {
            return null;
        }

        return CarbonImmutable::instance($genesis)->max(CarbonImmutable::now()->subDays($days));
    }

    /**
     * The forfeited seats of these players in rated matches since `$since`,
     * newest first. Read from the seat rows: `hyper_seats (user_id,
     * hyper_match_id)` narrows it to the players' own seats, and the match
     * is joined by its primary key. A voided match is no longer rated and
     * so does not count.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, stdClass> rows of `user_id` and `left_at`
     */
    private static function forfeits(array $userIds, CarbonImmutable $since): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return HyperSeat::query()->toBase()
            ->join('hyper_matches', 'hyper_matches.id', '=', 'hyper_seats.hyper_match_id')
            ->whereIn('hyper_seats.user_id', $userIds)
            ->where('hyper_seats.takeover', HyperSeat::TAKEOVER_FORFEIT)
            ->where('hyper_seats.left_at', '>=', $since)
            ->where('hyper_matches.rated', true)
            ->orderByDesc('hyper_seats.left_at')->orderByDesc('hyper_seats.id')
            ->get(['hyper_seats.user_id', 'hyper_seats.left_at']);
    }
}
