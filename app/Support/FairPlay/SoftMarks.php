<?php

namespace App\Support\FairPlay;

use App\Models\Rating;
use App\Models\RatingChange;
use Carbon\CarbonImmutable;

/**
 * The soft marks of an account, for admins only (plan "Profile standard and
 * impersonation", P3): what the league itself measured about how the
 * account plays with us, shown as chips on /admin/checks and on the
 * account's check view. Never in a player response, never a bar of their
 * own, never summed into a score. Thresholds and weights are
 * {@see AccountCheckSettings} (database, not config/): a weight of 0 hides
 * a mark, a threshold of 0 turns its "steady", "burst" or "zapped" reading
 * off (the count stays).
 *
 * - **activity**: the days with a rated or casual game with us (rating
 *   changes not reverted); "steady" from the threshold on, and "burst" when
 *   most games fell within one 24-hour stretch.
 * - **zaps**: the distinct known accounts (Verein members, accounts ranked
 *   high enough now) that zapped the account ({@see ReceivedZaps}); "not
 *   read yet" while its zaps were never read.
 * - **casual**: casual games with us before the live season's Block 0 (all
 *   casual games while no season is live).
 *
 * @phpstan-type Mark array{key: string, label: string, tone: 'good'|'warn'|'none', weight: int}
 */
final class SoftMarks
{
    /** Games read per account at most. */
    private const GAMES = 5000;

    /**
     * The marks of these accounts, pubkey => marks, highest weight first,
     * marks of weight 0 left out.
     *
     * @param  array<string, int>  $users  pubkey => user id
     * @return array<string, list<Mark>>
     */
    public static function for(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $byId = array_flip($users);
        $games = [];
        $casual = [];
        $blockZero = Checks::season()?->genesis_at;

        $changes = RatingChange::query()->whereNull('rating_changes.reverted_at')
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->whereIn('ratings.user_id', array_values($users))
            ->orderByDesc('rating_changes.id')->limit(self::GAMES * count($users))
            ->get(['ratings.user_id', 'ratings.pool', 'rating_changes.created_at']);

        foreach ($changes as $change) {
            $pubkey = $byId[(int) $change->getAttribute('user_id')] ?? null;
            $at = $change->created_at;

            if ($pubkey === null || $at === null) {
                continue;
            }

            $games[$pubkey][] = $at->getTimestamp();

            if ($change->getAttribute('pool') === Rating::CASUAL && ($blockZero === null || $at->lt($blockZero))) {
                $casual[$pubkey] = ($casual[$pubkey] ?? 0) + 1;
            }
        }

        $zappers = ReceivedZaps::knownZappers(array_keys($users), AccountCheckSettings::int('zaps_rank'));
        $zapsRead = ReceivedZaps::readAt(array_keys($users));
        $marks = [];

        foreach (array_keys($users) as $pubkey) {
            $marks[$pubkey] = self::sorted([
                ...self::activity($games[$pubkey] ?? []),
                self::zaps($zappers[$pubkey] ?? 0, isset($zapsRead[$pubkey])),
                self::casual($casual[$pubkey] ?? 0, $blockZero !== null),
            ]);
        }

        return $marks;
    }

    /**
     * @param  list<int>  $times
     * @return list<Mark>
     */
    private static function activity(array $times): array
    {
        $weight = AccountCheckSettings::int('activity_weight');

        if ($times === []) {
            return [self::mark('activity', __('No games with us'), 'none', $weight)];
        }

        $days = count(array_unique(array_map(fn (int $time): string => CarbonImmutable::createFromTimestamp($time)->format('Y-m-d'), $times)));
        $steady = AccountCheckSettings::int('activity_days');
        $marks = [$steady > 0 && $days >= $steady
            ? self::mark('activity', trans_choice('Steady: games on :count day|Steady: games on :count days', $days), 'good', $weight)
            : self::mark('activity', trans_choice('Games on :count day|Games on :count days', $days), 'none', $weight)];

        $share = intdiv(100 * self::busiestDay($times), count($times));
        $burstGames = AccountCheckSettings::int('activity_burst_games');
        $burstPercent = AccountCheckSettings::int('activity_burst_percent');

        if ($burstGames > 0 && $burstPercent > 0 && count($times) >= $burstGames && $share >= $burstPercent) {
            $marks[] = self::mark('burst', __('Burst: :percent % of games within 24 h', ['percent' => $share]), 'warn', $weight);
        }

        return $marks;
    }

    /**
     * The most games within any 24 hours.
     *
     * @param  list<int>  $times
     */
    private static function busiestDay(array $times): int
    {
        sort($times);
        $most = 0;
        $start = 0;

        foreach ($times as $end => $time) {
            while ($time - $times[$start] >= 86400) {
                $start++;
            }

            $most = max($most, $end - $start + 1);
        }

        return $most;
    }

    /** @return Mark */
    private static function zaps(int $known, bool $read): array
    {
        $weight = AccountCheckSettings::int('zaps_weight');

        if (! $read) {
            return self::mark('zaps', __('Zaps not read yet'), 'none', $weight);
        }

        $needed = AccountCheckSettings::int('zaps_accounts');

        return $needed > 0 && $known >= $needed
            ? self::mark('zaps', trans_choice('Zapped by :count known account|Zapped by :count known accounts', $known), 'good', $weight)
            : self::mark('zaps', trans_choice('Zaps from :count known account|Zaps from :count known accounts', $known), 'none', $weight);
    }

    /** @return Mark */
    private static function casual(int $games, bool $season): array
    {
        $label = $season
            ? trans_choice(':count casual game before Block 0|:count casual games before Block 0', $games)
            : trans_choice(':count casual game|:count casual games', $games);

        $needed = AccountCheckSettings::int('casual_games');

        return self::mark('casual', $label, $needed > 0 && $games >= $needed ? 'good' : 'none', AccountCheckSettings::int('casual_weight'));
    }

    /**
     * @param  'good'|'warn'|'none'  $tone
     * @return Mark
     */
    private static function mark(string $key, string $label, string $tone, int $weight): array
    {
        return ['key' => $key, 'label' => $label, 'tone' => $tone, 'weight' => $weight];
    }

    /**
     * @param  list<Mark>  $marks
     * @return list<Mark>
     */
    private static function sorted(array $marks): array
    {
        $marks = array_values(array_filter($marks, fn (array $mark): bool => $mark['weight'] > 0));
        // Stable: within one weight the order above stays.
        uasort($marks, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_values($marks);
    }
}
