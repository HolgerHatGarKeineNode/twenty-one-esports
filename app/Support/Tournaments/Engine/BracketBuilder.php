<?php

namespace App\Support\Tournaments\Engine;

use App\Enums\TournamentFormat;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;

/**
 * Builds the bracket of any format from the entrants, the options and the
 * seed. Pure and deterministic: the same entrants, options and seed always
 * give the same bracket, match keys included.
 */
final class BracketBuilder
{
    /**
     * @param  list<Entrant>  $entrants
     */
    public static function build(TournamentFormat $format, array $entrants, FormatOptions $options, string $seed): Bracket
    {
        $ordered = array_map(fn (Entrant $entrant): int => $entrant->id, Seeding::order($entrants, $seed));
        $slots = array_map(Slot::entrant(...), $ordered);
        $groups = [];

        $matches = match ($format) {
            TournamentFormat::SingleElimination => Elimination::single($slots, $options->thirdPlace),
            TournamentFormat::DoubleElimination => Elimination::double($slots, $options->grandFinal, $options->split),
            TournamentFormat::RoundRobin => RoundRobin::schedule($slots, $options->iterations),
            TournamentFormat::Swiss => count($ordered) < 2 ? [] : Swiss::pairRound(1, $ordered, [], $options->pointsWin, $options->pointsTie, $options->pointsBye),
            TournamentFormat::TwoStage => self::twoStage($ordered, $options, $groups),
            TournamentFormat::FreeForAll => self::freeForAll($slots, $options->heatSize, $options->heatAdvance),
            TournamentFormat::Leaderboard => $slots === [] ? [] : [new BracketMatch('board', 1, null, 'board', 1, 1, $slots)],
        };

        return new Bracket($format, $ordered, $groups, $matches);
    }

    /**
     * Balanced groups filled in snake order (A B C C B A …), then the final
     * stage fed by the group places: the group winners are the top seeds, then
     * the runners-up in group order. With 2 or 4 groups the standard seed
     * order keeps two of one group apart until the final (A1 meets B2 or D2);
     * with an odd group count a first-round rematch can happen.
     *
     * @param  list<int>  $ordered
     * @param  array<int, list<int>>  $groups
     * @return list<BracketMatch>
     */
    private static function twoStage(array $ordered, FormatOptions $options, array &$groups): array
    {
        $n = count($ordered);

        if ($n < 2) {
            return [];
        }

        $sizes = Estimator::groups($n, $options->groupSize);
        $groups = self::snake($ordered, $sizes);
        $matches = [];

        foreach ($groups as $number => $members) {
            $seeds = array_map(Slot::entrant(...), $members);
            $prefix = "g{$number}-";

            array_push($matches, ...match ($options->groupStage) {
                'single-elimination' => Elimination::single($seeds, false, $prefix, 1, $number),
                'double-elimination' => Elimination::double($seeds, 'skip', false, $prefix, 1, $number),
                default => RoundRobin::schedule($seeds, 1, $prefix, 1, $number),
            });
        }

        $advance = min($options->advance, min($sizes));
        $finalSeeds = [];

        for ($rank = 1; $rank <= $advance; $rank++) {
            foreach (array_keys($groups) as $number) {
                $finalSeeds[] = Slot::groupRank($number, $rank);
            }
        }

        array_push($matches, ...($options->finalStage === 'double-elimination'
            ? Elimination::double($finalSeeds, 'single', false, 'f-', 2)
            : Elimination::single($finalSeeds, false, 'f-', 2)));

        return $matches;
    }

    /**
     * @param  list<int>  $ordered
     * @param  list<int>  $sizes
     * @return array<int, list<int>> group number (1-based) => members
     */
    private static function snake(array $ordered, array $sizes): array
    {
        $groups = [];
        $count = count($sizes);

        foreach (array_keys($sizes) as $index) {
            $groups[$index + 1] = [];
        }

        $forward = true;
        $queue = $ordered;

        while ($queue !== []) {
            $indexes = $forward ? range(0, $count - 1) : range($count - 1, 0);

            foreach ($indexes as $index) {
                if ($queue === []) {
                    break;
                }

                if (count($groups[$index + 1]) < $sizes[$index]) {
                    $groups[$index + 1][] = array_shift($queue);
                }
            }

            $forward = ! $forward;
        }

        return $groups;
    }

    /**
     * Heats of `$size`, the best `$advance` of each move on, until one final
     * heat is left (the estimator's `tfFFA`).
     *
     * @param  list<Slot>  $slots
     * @return list<BracketMatch>
     */
    private static function freeForAll(array $slots, int $size, int $advance): array
    {
        $matches = [];
        $round = 1;
        $guard = 0;

        if ($slots === []) {
            return [];
        }

        while (count($slots) > $size && $guard < 20) {
            $heats = (int) ceil(count($slots) / $size);
            $members = array_fill(0, $heats, []);

            foreach (self::snake(array_keys($slots), array_fill(0, $heats, $size)) as $heat => $indexes) {
                $members[$heat - 1] = array_map(fn (int $index): Slot => $slots[$index], $indexes);
            }

            $next = [];

            foreach ($members as $heat => $heatSlots) {
                $key = "h{$round}-".($heat + 1);
                $matches[] = new BracketMatch($key, 1, null, 'heat', $round, $heat + 1, $heatSlots);
            }

            for ($rank = 1; $rank <= $advance; $rank++) {
                foreach (array_keys($members) as $heat) {
                    if ($rank <= count($members[$heat])) {
                        $next[] = Slot::rankOf("h{$round}-".($heat + 1), $rank);
                    }
                }
            }

            $slots = $next;
            $round++;
            $guard++;
        }

        $matches[] = new BracketMatch('final', 1, null, 'heat', $round, 1, $slots);

        return $matches;
    }
}
