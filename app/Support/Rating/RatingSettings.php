<?php

namespace App\Support\Rating;

use App\Models\Season;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Board;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\Seasons;
use Illuminate\Support\Facades\DB;

/**
 * The rating, rank and hashrate values of a season (AdminSeason, P35).
 * config/season.php stays the default; an admin edits a draft for the next
 * Block 0, and the release freezes the draft onto the season row, as the
 * season's ladders (`32152`) freeze it in their `rating`, `tier`,
 * `provisional` and `hashrate` tags.
 *
 * Decided per value of config/season.php:
 *
 * - `rating.start`, `rating.k`, `rating.provisional_k`, `rating.provisional`,
 *   `rating.scale`, `tiers`, `hashrate`: admin-editable in the draft, LOCKED
 *   once a season has been released. The signed ladders carry them, and a
 *   reader replays every rating from the ladder's tags (NIP "Ladder",
 *   "Season transition"); a change during or after the season would rewrite
 *   the history those events state.
 * - `rating.daily_pair_limit`: editable and locked the same way. It is no
 *   ladder tag, but it decides which rated results move a rating, so a
 *   change during the season would make the season's ratings depend on the
 *   day the change was saved.
 * - `casual.*`: not editable here. Casual ratings are permanent, not per
 *   season (user decision 2026-09-26).
 * - `global_rating_min_weight`, `clan_rating_top`, `estimator.*`: not
 *   editable here. The first two are display rules, the estimator is a
 *   forecast setting.
 * - `chain.*` and `trust_minimum`: the chain draft (ChainDraft, P43), in the
 *   same rows under `chain`, with the same board rule, lock and log; during
 *   a season the board changes the allowed chain rules through Parameter
 *   Changes (`2158`).
 *
 * The draft is for the next Block 0: the Pre-Season, then each season the
 * board plans (SeasonPlans, P38). It is locked while a season is live, so
 * the page shows the live season's values, and open again once that season
 * has ended, for the next one; a season's own values never change after its
 * release.
 *
 * Which values are in force: the newest released season's frozen values
 * (live or ended, so an ended ladder keeps its own tiers); before any
 * release the draft. The database lookup is kept once per request or queue
 * job, and forgotten whenever a season or a draft change is saved.
 *
 * @phpstan-import-type Chain from ChainDraft as ChainDraftValues
 *
 * @phpstan-type Values array{rating: array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null}, tiers: array<string, int>, hashrate: array{win: int, draw: int, loss: int, team_win_bonus: int}}
 */
final class RatingSettings
{
    private const MEMO = 'rating-settings.stored';

    /** Validation limits per field: [minimum, maximum]. */
    public const LIMITS = [
        'rating.start' => [100, 3000],
        'rating.k' => [1, 100],
        'rating.provisional_k' => [1, 100],
        'rating.provisional' => [0, 50],
        'rating.scale' => [100, 1000],
        'rating.daily_pair_limit' => [1, 100],
        'tiers' => [0, 5000],
        'hashrate' => [0, 100],
    ];

    /**
     * config/season.php.
     *
     * @return Values
     */
    public static function defaults(): array
    {
        /** @var array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null} $rating */
        $rating = config('season.rating');
        /** @var array<string, int> $tiers */
        $tiers = config('season.tiers');
        /** @var array{win: int, draw: int, loss: int, team_win_bonus: int} $hashrate */
        $hashrate = config('season.hashrate');

        return [
            'rating' => [
                'start' => (int) $rating['start'],
                'k' => (int) $rating['k'],
                'provisional_k' => (int) $rating['provisional_k'],
                'provisional' => (int) $rating['provisional'],
                'scale' => (int) $rating['scale'],
                'daily_pair_limit' => $rating['daily_pair_limit'] === null ? null : (int) $rating['daily_pair_limit'],
            ],
            'tiers' => array_map(intval(...), $tiers),
            'hashrate' => [
                'win' => (int) $hashrate['win'],
                'draw' => (int) $hashrate['draw'],
                'loss' => (int) $hashrate['loss'],
                'team_win_bonus' => (int) $hashrate['team_win_bonus'],
            ],
        ];
    }

    /**
     * The draft for the next Block 0: the newest admin change, else config.
     *
     * @return Values
     */
    public static function draft(): array
    {
        /** @var SeasonSettingChange|null $latest */
        $latest = SeasonSettingChange::query()->latest('id')->first();

        return $latest === null ? self::defaults() : self::only($latest->values);
    }

    /**
     * The rating, rank and hashrate values of a stored draft, without the
     * chain draft next to them.
     *
     * @param  array<string, mixed>  $values
     * @return Values
     */
    private static function only(array $values): array
    {
        /** @var Values */
        return array_intersect_key($values, array_flip(['rating', 'tiers', 'hashrate']));
    }

    /**
     * What the season froze at Block 0; config for a season released before
     * P35.
     *
     * @return Values
     */
    public static function forSeason(Season $season): array
    {
        return $season->rating_parameters ?? self::defaults();
    }

    /**
     * The values the rating engine, the ranks and the clan hashrate use now.
     *
     * @return Values
     */
    public static function inForce(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::MEMO)) {
            $season = Season::query()->latest('genesis_at')->first();
            $values = $season === null ? SeasonSettingChange::query()->latest('id')->first()?->values : null;
            $stored = $season !== null ? $season->rating_parameters : ($values === null ? null : self::only($values));
            $attributes->set(self::MEMO, $stored);
        }

        /** @var Values|null $stored */
        $stored = $attributes->get(self::MEMO);

        return $stored ?? self::defaults();
    }

    public static function forget(): void
    {
        request()->attributes->remove(self::MEMO);
        request()->attributes->remove(ChainDraft::MEMO);
    }

    /** While a season is live the draft cannot change; before the first and between seasons it can. */
    public static function locked(): bool
    {
        return Seasons::live() !== null;
    }

    /**
     * Save a new draft and its audit row; null when nothing changed. Only a
     * board member on the public admin list may (P39), as for the release of
     * Block 0 (SeasonRelease::refusal()). Refused once a season has been
     * released; that check runs inside the transaction, so a release that
     * commits first wins.
     *
     * $chain is the chain draft (ChainDraft, P43), saved in the same row;
     * null keeps the chain draft as it is. The first chain draft is always
     * written, even with the defaults, because only a saved one is shown on
     * the public pages.
     *
     * @param  Values  $values  validated
     * @param  array<string, mixed>|null  $chain  validated (ChainDraft::fromInput())
     *
     * @throws SeasonReleaseRefused
     */
    public static function saveDraft(User $admin, array $values, ?array $chain = null): ?SeasonSettingChange
    {
        if (! Board::contains($admin->pubkey)) {
            throw new SeasonReleaseRefused(__('Only a board member on the public admin list can change these values.'));
        }

        return DB::transaction(function () use ($admin, $values, $chain): ?SeasonSettingChange {
            if (self::locked()) {
                throw new SeasonReleaseRefused(__('A season has been released: its rating, rank and hashrate values are frozen in its signed ladders and cannot change.'));
            }

            $stored = ChainDraft::stored();
            $changes = self::diff(self::draft(), $values);

            if ($chain !== null) {
                /** @var ChainDraftValues $chain */
                $changes = [...$changes, ...ChainDraft::diff($stored ?? ChainDraft::defaults(), $chain)];
            }

            if ($changes === [] && ($chain === null || $stored !== null)) {
                return null;
            }

            $chain ??= $stored;

            return SeasonSettingChange::query()->create([
                'changed_by_id' => $admin->id,
                'changed_by_pubkey' => $admin->pubkey,
                'values' => $chain === null ? $values : [...$values, 'chain' => $chain],
                'changes' => $changes,
            ]);
        });
    }

    /**
     * Dot path => [before, after] for every value that differs.
     *
     * @param  Values  $before
     * @param  Values  $after
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (['rating', 'tiers', 'hashrate'] as $group) {
            foreach ($after[$group] as $key => $value) {
                $old = $before[$group][$key] ?? null;

                if ($old !== $value) {
                    $changes[$group.'.'.$key] = [$old, $value];
                }
            }
        }

        return $changes;
    }
}
