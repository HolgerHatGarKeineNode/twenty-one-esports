<?php

namespace App\Support\Rating;

use App\Models\Rating;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;

/**
 * The soft reset between two seasons (docs/nips/esports.md, "Season
 * transition"): every entity with at least one rated result in season A
 * starts season B at
 *
 *   seed = start_B + round((final_A - start_A) * f)
 *
 * with the rounding of Rating (halves away from zero), f between 0 (hard
 * reset) and 1 (full carry-over). f is taken as a decimal with at most three
 * places and the product is computed in integers, so 50 * 0.29 is 14.5 and
 * rounds to 15; in floats it is 14.4999… and rounds to 14.
 *
 * AdminSeason shows it as a read-only preview. Nothing applies it yet: a
 * release writes no `reset`/`seed` tags, and only the Pre-Season can be
 * released (SeasonRelease).
 */
final class SoftReset
{
    /** "0.5", "0,5", "1", "0.125"; null for anything else. In thousandths. */
    public static function factorMilli(string $input): ?int
    {
        $normalized = str_replace(',', '.', trim($input));

        if (preg_match('/^(0(\.\d{1,3})?|1(\.0{1,3})?|\.\d{1,3})$/', $normalized) !== 1) {
            return null;
        }

        return (int) round((float) $normalized * 1000);
    }

    public static function seed(int $finalA, int $startA, int $startB, int $factorMilli): int
    {
        $product = ($finalA - $startA) * $factorMilli;
        $carried = intdiv(abs($product) + 500, 1000);

        return $startB + ($product < 0 ? -$carried : $carried);
    }

    /**
     * The rated rows season A carries over: at least one rated result,
     * grouped by ladder, best first.
     *
     * @return Builder<Rating>
     */
    public static function carriedOver(Season $season): Builder
    {
        return Rating::query()
            ->with(['user', 'lineup.clan'])
            ->where(['pool' => Rating::RATED, 'season' => $season->slug])
            ->where('results', '>', 0)
            ->orderBy('game')->orderBy('mode')->orderByDesc('rating')->orderBy('id');
    }
}
