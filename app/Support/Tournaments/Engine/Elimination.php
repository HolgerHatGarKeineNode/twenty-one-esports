<?php

namespace App\Support\Tournaments\Engine;

use App\Support\Tournaments\Estimator;

/**
 * Single and double elimination brackets over seeds (a whole tournament, one
 * group, or the final stage of a two-stage tournament fed by group places).
 *
 * The bracket is laid out for the next power of two P in the standard seed
 * order (1 meets P, 1 and 2 only in the final). Byes go to the top seeds and
 * are not matches: a seed with a bye starts in round 2. In double elimination
 * a bye has no loser, so the lower-bracket match it would have fed collapses
 * too, earliest round first — the same match counts the estimator plans with.
 */
final class Elimination
{
    /** @var list<BracketMatch> */
    private array $matches = [];

    /** @var array<string, int> next position per bracket and round */
    private array $positions = [];

    private function __construct(
        private readonly string $prefix,
        private readonly int $stage,
        private readonly ?int $group,
    ) {}

    /**
     * @param  list<Slot>  $seeds  seed 1 first
     * @return list<BracketMatch>
     */
    public static function single(array $seeds, bool $thirdPlace, string $prefix = '', int $stage = 1, ?int $group = null): array
    {
        $builder = new self($prefix, $stage, $group);
        $n = count($seeds);

        if ($n < 2) {
            return [];
        }

        $feeds = $builder->firstRound($seeds, 'main', 'm', split: false)['winners'];
        $round = 2;

        while (count($feeds) > 1) {
            $next = [];

            for ($i = 0; $i < count($feeds); $i += 2) {
                $next[] = $builder->pair($feeds[$i], $feeds[$i + 1], 'main', 'm', $round, $round);
            }

            $feeds = $next;
            $round++;
        }

        $final = $builder->matches[count($builder->matches) - 1];

        if ($thirdPlace && $final->slots[0]->take === 'winner' && $final->slots[1]->take === 'winner') {
            $builder->matches[] = new BracketMatch($prefix.'3rd', $stage, $group, 'third-place', $final->round, 1,
                [Slot::loserOf((string) $final->slots[0]->match), Slot::loserOf((string) $final->slots[1]->match)]);
        }

        return $builder->matches;
    }

    /**
     * @param  list<Slot>  $seeds  seed 1 first
     * @param  'reset'|'single'|'skip'  $grandFinal
     * @param  bool  $split  seeds beyond half the bracket start in the lower bracket
     * @return list<BracketMatch>
     */
    public static function double(array $seeds, string $grandFinal, bool $split = false, string $prefix = '', int $stage = 1, ?int $group = null): array
    {
        $n = count($seeds);

        if ($n < 3) {
            return self::single($seeds, false, $prefix, $stage, $group);
        }

        $builder = new self($prefix, $stage, $group);
        $r = Estimator::log2($n);
        $lowerRounds = 2 * ($r - 1);

        $first = $builder->firstRound($seeds, 'upper', 'u', $split);
        $upper = $first['winners'];
        $losers = [1 => $first['losers']];

        for ($round = 2; $round <= $r; $round++) {
            $next = [];
            $losers[$round] = [];

            for ($i = 0; $i < count($upper); $i += 2) {
                $winner = $builder->pair($upper[$i], $upper[$i + 1], 'upper', 'u', $round, $round);
                $next[] = $winner;
                $losers[$round][] = $winner === null ? null : Slot::loserOf((string) $winner->match);
            }

            $upper = $next;
        }

        // Lower round 1: the losers of upper round 1, two by two.
        $lower = [];

        for ($i = 0; $i < count($losers[1]); $i += 2) {
            $lower[] = $builder->pair($losers[1][$i], $losers[1][$i + 1], 'lower', 'l', 1, 2);
        }

        for ($i = 1; $i <= $r - 1; $i++) {
            // Even lower rounds take the losers of the next upper round, every
            // other time in reverse order so early opponents don't meet again at once.
            $drop = $i % 2 === 1 ? array_reverse($losers[$i + 1]) : $losers[$i + 1];
            $next = [];

            foreach ($lower as $k => $slot) {
                $next[] = $builder->pair($slot, $drop[$k], 'lower', 'l', 2 * $i, 2 * $i + 1);
            }

            $lower = $next;

            if ($i < $r - 1) {
                $next = [];

                for ($k = 0; $k < count($lower); $k += 2) {
                    $next[] = $builder->pair($lower[$k], $lower[$k + 1], 'lower', 'l', 2 * $i + 1, 2 * $i + 2);
                }

                $lower = $next;
            }
        }

        if ($grandFinal !== 'skip' && $upper[0] !== null && $lower[0] !== null) {
            $builder->matches[] = new BracketMatch($prefix.'gf', $stage, $group, 'grand-final', $lowerRounds + 2, 1, [$upper[0], $lower[0]]);

            if ($grandFinal === 'reset') {
                $builder->matches[] = new BracketMatch($prefix.'gf2', $stage, $group, 'reset', $lowerRounds + 3, 1,
                    [Slot::winnerOf($prefix.'gf'), Slot::loserOf($prefix.'gf')], ifNeeded: true);
            }
        }

        return self::compressRounds($builder->matches);
    }

    /**
     * Round 1 of the bracket in standard seed order. A pair with a bye is no
     * match: the seed moves on and there is no loser. With `$split`, round 1
     * is not played: the higher seed moves on, the lower one drops to the lower bracket.
     *
     * @param  list<Slot>  $seeds
     * @return array{winners: list<Slot|null>, losers: list<Slot|null>}
     */
    private function firstRound(array $seeds, string $bracket, string $code, bool $split): array
    {
        $n = count($seeds);
        $size = 1 << Estimator::log2($n);
        $order = Seeding::bracketOrder($size);
        $winners = [];
        $losers = [];

        for ($i = 0; $i < $size; $i += 2) {
            $a = $order[$i] <= $n ? $seeds[$order[$i] - 1] : null;
            $b = $order[$i + 1] <= $n ? $seeds[$order[$i + 1] - 1] : null;

            if ($a !== null && $b !== null && $split) {
                $winners[] = $a;
                $losers[] = $b;

                continue;
            }

            $winner = $this->pair($a, $b, $bracket, $code, 1, 1);
            $winners[] = $winner;
            $losers[] = $a !== null && $b !== null && $winner !== null ? Slot::loserOf((string) $winner->match) : null;
        }

        return ['winners' => $winners, 'losers' => $losers];
    }

    /**
     * A match between two sides, or the one side that is there (a bye), or nothing.
     */
    private function pair(?Slot $a, ?Slot $b, string $bracket, string $code, int $round, int $step): ?Slot
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        $counter = "{$code}{$round}";
        $position = $this->positions[$counter] = ($this->positions[$counter] ?? 0) + 1;
        $key = "{$this->prefix}{$code}{$round}-{$position}";

        $this->matches[] = new BracketMatch($key, $this->stage, $this->group, $bracket, $step, $position, [$a, $b]);

        return Slot::winnerOf($key);
    }

    /**
     * Number the time steps 1, 2, 3 … without the ones no match is left in.
     *
     * @param  list<BracketMatch>  $matches
     * @return list<BracketMatch>
     */
    private static function compressRounds(array $matches): array
    {
        $steps = array_values(array_unique(array_map(fn (BracketMatch $match): int => $match->round, $matches)));
        sort($steps);
        $map = array_flip($steps);

        return array_map(fn (BracketMatch $match): BracketMatch => $match->withRound($map[$match->round] + 1), $matches);
    }
}
