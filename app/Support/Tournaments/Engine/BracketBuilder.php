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
    /** Most seed orders of a two-stage final tried before the fewest clashes win. */
    private const FINAL_SEED_TRIES = 500;

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
     * stage fed by the group places: the group winners are the top seeds,
     * then the other places. The order of the groups within each place is a
     * rotation (forward or reversed), the first combination that lets no two
     * of one group meet in their first final-stage match.
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
        $finalSeeds = self::finalSeeds(array_keys($groups), $advance);

        array_push($matches, ...($options->finalStage === 'double-elimination'
            ? Elimination::double($finalSeeds, 'single', false, 'f-', 2)
            : Elimination::single($finalSeeds, false, 'f-', 2)));

        return $matches;
    }

    /**
     * @param  list<int>  $groups  group numbers
     * @return list<Slot>
     */
    private static function finalSeeds(array $groups, int $advance): array
    {
        $count = count($groups);
        $orders = [];

        foreach ([$groups, array_reverse($groups)] as $order) {
            for ($shift = 0; $shift < $count; $shift++) {
                $orders[] = [...array_slice($order, $shift), ...array_slice($order, 0, $shift)];
            }
        }

        $winners = array_map(fn (int $number): array => [$number, 1], $groups);
        $search = ['best' => $winners, 'fewest' => PHP_INT_MAX, 'tried' => 0];
        self::searchFinalSeeds($winners, 2, $advance, $orders, $search);

        return array_map(fn (array $seed): Slot => Slot::groupRank($seed[0], $seed[1]), $search['best']);
    }

    /**
     * Every place below the winners gets its own group order; the first
     * combination without a clash wins, else the one with the fewest.
     * Returns true to stop the search.
     *
     * @param  list<array{0: int, 1: int}>  $seeds
     * @param  list<list<int>>  $orders
     * @param  array{best: list<array{0: int, 1: int}>, fewest: int, tried: int}  $search
     */
    private static function searchFinalSeeds(array $seeds, int $rank, int $advance, array $orders, array &$search): bool
    {
        if ($rank > $advance) {
            $clashes = self::firstMeetingClashes($seeds);
            $search['tried']++;

            if ($clashes < $search['fewest']) {
                $search['best'] = $seeds;
                $search['fewest'] = $clashes;
            }

            return $clashes === 0 || $search['tried'] >= self::FINAL_SEED_TRIES;
        }

        foreach ($orders as $order) {
            $next = $seeds;

            foreach ($order as $number) {
                $next[] = [$number, $rank];
            }

            if (self::searchFinalSeeds($next, $rank + 1, $advance, $orders, $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * First meetings of two of the same group in a bracket over these seeds:
     * a seed with a bye meets its first opponent later, so the tree is walked
     * up until each seed has met somebody.
     *
     * @param  list<array{0: int, 1: int}>  $seeds  [group, place]
     */
    private static function firstMeetingClashes(array $seeds): int
    {
        $size = 1 << Estimator::log2(count($seeds));
        // A node is a seed that has not played yet, 'match' once somebody has, or null (empty).
        $nodes = array_map(fn (int $seed): ?array => $seeds[$seed - 1] ?? null, Seeding::bracketOrder($size));
        $clashes = 0;

        while (count($nodes) > 1) {
            $next = [];

            for ($i = 0; $i < count($nodes); $i += 2) {
                [$a, $b] = [$nodes[$i], $nodes[$i + 1]];

                if ($a === null || $b === null) {
                    $next[] = $a ?? $b;

                    continue;
                }

                $clashes += is_array($a) && is_array($b) && $a[0] === $b[0] ? 1 : 0;
                $next[] = 'match';
            }

            $nodes = $next;
        }

        return $clashes;
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
