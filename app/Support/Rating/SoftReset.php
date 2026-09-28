<?php

namespace App\Support\Rating;

use App\Games\GameRegistry;
use App\Models\Rating;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

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
 * AdminSeason shows it as a preview with the planned f (SeasonPlans), and
 * the release of a planned season applies it (SeasonRelease, P38): apply()
 * writes the new season's rated rows at their seeds, and LadderEvents puts
 * the same seeds into the first version of the new ladders as `seed` tags.
 * final_A is the entity's rated row of season A as the league holds it at
 * the release; start_A and start_B are the start ratings the two seasons
 * froze (RatingSettings::forSeason()).
 *
 * Only entities the new ladder can name are seeded: a player in a player
 * ladder (its pubkey), a lineup in a lineup ladder (its address). A row of
 * a deleted player or lineup, or of the other kind (rev. 6 lineup rows in a
 * player ladder), is carried nowhere, as it has no standing either.
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
     * The seeds of season $to per ladder (`<game>/<mode>`), best first: the
     * row of season $from each one comes from, the entity as the ladder
     * names it and the seed. $to must carry its factor
     * (`reset_factor_milli`).
     *
     * @return array<string, list<array{row: Rating, entity: string, seed: int}>>
     */
    public static function seeds(Season $from, Season $to): array
    {
        $factor = $to->reset_factor_milli ?? throw new InvalidArgumentException('The season carries no carry-over factor.');
        $startFrom = RatingSettings::forSeason($from)['rating']['start'];
        $startTo = RatingSettings::forSeason($to)['rating']['start'];
        $games = app(GameRegistry::class);
        $seeds = [];

        foreach (self::carriedOver($from)->get() as $row) {
            $player = ($games->mode($row->game, $row->mode)->rates ?? 'lineup') === 'player';
            $entity = $player ? $row->user?->pubkey : ($row->lineup?->clan !== null ? $row->lineup->address() : null);

            if (! is_string($entity) || $entity === '') {
                continue;
            }

            $seeds[$row->game.'/'.$row->mode][] = ['row' => $row, 'entity' => $entity, 'seed' => self::seed($row->rating, $startFrom, $startTo, $factor)];
        }

        return $seeds;
    }

    /**
     * Write season $to's rated rows at their seeds, with no result yet. Rows
     * of $from and every casual row stay as they are. Returns the rows
     * written.
     */
    public static function apply(Season $from, Season $to): int
    {
        $rows = [];

        foreach (self::seeds($from, $to) as $ladder) {
            foreach ($ladder as $seed) {
                $row = $seed['row'];
                $rows[] = [
                    'pool' => Rating::RATED,
                    'season' => $to->slug,
                    'game' => $row->game,
                    'mode' => $row->mode,
                    'subject' => $row->subject,
                    'user_id' => $row->user_id,
                    'lineup_id' => $row->lineup_id,
                    'rating' => $seed['seed'],
                    'results' => 0,
                    'wins' => 0,
                    'draws' => 0,
                    'losses' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Rating::query()->insert($chunk);
        }

        return count($rows);
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
