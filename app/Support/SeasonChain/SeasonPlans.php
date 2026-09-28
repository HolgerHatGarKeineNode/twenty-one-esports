<?php

namespace App\Support\SeasonChain;

use App\Models\Season;
use App\Models\SeasonPlan;
use App\Models\User;
use App\Support\Board;
use App\Support\Rating\SoftReset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The season planner (P38, AdminSeason): the board plans the season after
 * the newest one, its name, planned Block 0, length and the carry-over
 * factor f of the soft reset (NIP "Season transition"; user decision
 * 2026-09-28: f = 0.5 by default). The board releases it like Block 0
 * (SeasonRelease), from its planned Block 0 on and only after the season
 * before it has ended.
 *
 * Scheduling a season publishes its announcement (`31923`, `start` = the
 * planned Block 0, `end` = the planned end; NIP "Season Genesis",
 * "Release"), and every change of the plan publishes a new version of it.
 * Every change is a new season_plans row with who changed what; rows are
 * never updated.
 *
 * The slug is not chosen: after the Pre-Season come `season-1`,
 * `season-2`, … (NIP "Season chain"). The chain parameters (supply,
 * subsidy, weights, …) stay config/season.php and the rating values the
 * rating draft (RatingSettings), as for the Pre-Season.
 *
 * Fail closed: for anyone not on the board, before any season, without the
 * league key or with a value out of range nothing is written or signed.
 */
final class SeasonPlans
{
    public const NAME_MAX = 60;

    /** The plan in force: the newest row for the newest season, or null. */
    public static function current(): ?SeasonPlan
    {
        $latest = Seasons::latest();

        return $latest === null ? null : SeasonPlan::query()->where('after_season_id', $latest->id)->latest('id')->first();
    }

    /** `pre-season` => `season-1`, `season-4` => `season-5`. */
    public static function nextSlug(Season $after): string
    {
        return preg_match('/^season-(\d+)$/', $after->slug, $match) === 1 ? 'season-'.((int) $match[1] + 1) : 'season-1';
    }

    /**
     * What the form shows before the first plan: the next slug's name, Block 0
     * at the end of the season before it (or the next full hour, if that has
     * passed), the Pre-Season's length and f = 0.5.
     *
     * @return array{name: string, starts_at: CarbonImmutable, weeks: int, reset_factor_milli: int}
     */
    public static function defaults(Season $after): array
    {
        $end = CarbonImmutable::instance($after->ends_at);
        $nextHour = CarbonImmutable::now()->addHour()->startOfHour();
        /** @var array{eras: int, halving_seconds: int} $chain */
        $chain = config('season.chain');

        return [
            'name' => __('Season :number', ['number' => substr(self::nextSlug($after), 7)]),
            'starts_at' => $end->isFuture() ? $end : $nextHour,
            'weeks' => max(self::minWeeks(), min(self::maxWeeks(), intdiv($chain['eras'] * $chain['halving_seconds'], 604800))),
            'reset_factor_milli' => 500,
        ];
    }

    public static function minWeeks(): int
    {
        return (int) config('season.estimator.min_weeks');
    }

    public static function maxWeeks(): int
    {
        return (int) config('season.estimator.max_weeks');
    }

    /** Why this admin cannot plan now, or null. */
    public static function refusal(?User $admin): ?string
    {
        if ($admin === null || ! Board::contains($admin->pubkey)) {
            return __('Only a board member on the public admin list can plan a season.');
        }

        if (Seasons::latest() === null) {
            return __('The planner is for the seasons after the Pre-Season. Release the Pre-Season first.');
        }

        if (LeagueKey::fromConfig() === null) {
            return __('The league key is not set on this server, so the season cannot be announced.');
        }

        return null;
    }

    /**
     * Save the plan and publish its announcement; null when nothing changed.
     *
     * @throws SeasonReleaseRefused
     */
    public function save(User $admin, string $name, ?CarbonImmutable $startsAt, string $weeks, string $factor): ?SeasonPlan
    {
        $refusal = self::refusal($admin);

        if ($refusal !== null) {
            throw new SeasonReleaseRefused($refusal);
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new SeasonReleaseRefused(__('Give the season a name, up to :max characters.', ['max' => self::NAME_MAX]));
        }

        $weeks = trim($weeks);

        if (preg_match('/^\d{1,3}$/', $weeks) !== 1 || (int) $weeks < self::minWeeks() || (int) $weeks > self::maxWeeks()) {
            throw new SeasonReleaseRefused(__('The length is a whole number of weeks from :min to :max.', ['min' => self::minWeeks(), 'max' => self::maxWeeks()]));
        }

        $factorMilli = SoftReset::factorMilli($factor)
            ?? throw new SeasonReleaseRefused(__('The factor is a number from 0 to 1 with at most three decimals.'));

        if ($startsAt === null) {
            throw new SeasonReleaseRefused(__('Choose the date and time of Block 0.'));
        }

        // In UTC: the datetime cast stores the wall time of whatever zone it gets.
        $startsAt = $startsAt->utc()->startOfMinute();
        $league = LeagueKey::required();

        return DB::transaction(function () use ($admin, $name, $startsAt, $weeks, $factorMilli, $league): ?SeasonPlan {
            // One writer at a time, and never a plan for a season that has a successor already.
            $after = Season::query()->whereKey(Seasons::latest()?->id)->lockForUpdate()->firstOrFail();

            if (Season::query()->where('previous_season_id', $after->id)->exists()) {
                throw new SeasonReleaseRefused(__('The next season has been released. Plan the one after it once the page shows it.'));
            }

            if ($startsAt->getTimestamp() < $after->ends_at->getTimestamp()) {
                throw new SeasonReleaseRefused(__('Block 0 of the next season can only be after :season ends.', ['season' => $after->slug]));
            }

            if (! $startsAt->isFuture()) {
                throw new SeasonReleaseRefused(__('Block 0 must be in the future.'));
            }

            $current = SeasonPlan::query()->where('after_season_id', $after->id)->latest('id')->first();
            $values = ['name' => $name, 'starts_at' => $startsAt->getTimestamp(), 'weeks' => (int) $weeks, 'reset_factor_milli' => $factorMilli];
            $before = $current === null ? [] : ['name' => $current->name, 'starts_at' => $current->starts_at->getTimestamp(), 'weeks' => $current->weeks, 'reset_factor_milli' => $current->reset_factor_milli];
            $changes = [];

            foreach ($values as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $changes[$field] = [$before[$field] ?? null, $value];
                }
            }

            if ($changes === []) {
                return null;
            }

            $slug = self::nextSlug($after);
            $announcement = app(SeasonRelease::class)->announce($league, $slug, $name, $startsAt->getTimestamp(), $startsAt->getTimestamp() + (int) $weeks * 604800);

            return SeasonPlan::query()->create([
                'after_season_id' => $after->id,
                'slug' => $slug,
                'name' => $name,
                'starts_at' => $startsAt,
                'weeks' => (int) $weeks,
                'reset_factor_milli' => $factorMilli,
                'changed_by_id' => $admin->id,
                'changed_by_pubkey' => $admin->pubkey,
                'changes' => $changes,
                'announcement_event_id' => $announcement->id,
            ]);
        });
    }
}
