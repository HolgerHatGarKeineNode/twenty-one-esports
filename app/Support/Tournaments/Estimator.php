<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;

/**
 * The tournament estimator and recommendation of the format chooser, a port
 * of the artboard script (`tf*` in AdminTournamentCreate.dc.html) whose
 * formulas TOURNAMENT-FORMATS.md, sections 3 and 4, calls "the code". The
 * worked examples of section 5 are pinned in tests/Unit/Tournaments.
 *
 * One deviation, a decision of the user from 2026-09-26 that came after the
 * worked examples: for Rocket League a format that ends in a final comes
 * before "most guaranteed games" (chess keeps the documented order).
 */
final class Estimator
{
    /** Most games one daily-chess player should run at the same time (assumption). */
    public const AT_ONCE_MAX = 12;

    /** Up to this share over the time counts as a near miss ("up to 10 % over"). */
    public const NEAR_MISS = 1.1;

    public static function log2(int $n): int
    {
        $rounds = 0;

        while ((1 << $rounds) < $n) {
            $rounds++;
        }

        return $rounds;
    }

    /** Challonge's two-thirds rule, and never more than N − 1 rounds. */
    public static function swissMax(int $n): int
    {
        return max(1, min($n - 1, (int) ceil(2 * $n / 3) - 1));
    }

    public static function swissDefault(int $n): int
    {
        return max(1, min(self::log2($n), self::swissMax($n)));
    }

    public function singleElimination(int $n, bool $thirdPlace): Structure
    {
        if ($n < 2) {
            return new Structure([], 0, 0, 0, 0);
        }

        $r = self::log2($n);
        $p = 1 << $r;
        $third = $thirdPlace && $r >= 2;
        $rounds = [];

        // The match for 3rd place runs next to the final.
        for ($i = 1; $i <= $r; $i++) {
            $matches = $i === 1 ? $n - intdiv($p, 2) : $p >> $i;
            $rounds[] = ['m' => $matches + ($third && $i === $r ? 1 : 0), 'final' => $i === $r, 'ifNeeded' => false];
        }

        return new Structure($rounds, $n - 1 + ($third ? 1 : 0), 1, $r, 1, byes: $p - $n);
    }

    /**
     * @param  'reset'|'single'|'skip'  $grandFinal
     */
    public function doubleElimination(int $n, string $grandFinal): Structure
    {
        if ($n < 3) {
            return $this->singleElimination($n, false);
        }

        $r = self::log2($n);
        $p = 1 << $r;
        $upper = [$n - intdiv($p, 2)];

        for ($i = 2; $i <= $r; $i++) {
            $upper[] = $p >> $i;
        }

        $lowerRounds = 2 * ($r - 1);
        $lower = [];

        for ($j = 1; $j <= $lowerRounds; $j++) {
            $lower[] = $p >> ((int) ceil($j / 2) + 1);
        }

        // The byes of the upper bracket take matches out of the lower one, earliest round first.
        $cut = $p - $n;

        for ($j = 0; $j < count($lower) && $cut > 0; $j++) {
            $taken = min($cut, $lower[$j]);
            $lower[$j] -= $taken;
            $cut -= $taken;
        }

        $steps = [$upper[0]];

        for ($i = 1; $i < $r; $i++) {
            $steps[] = $upper[$i] + $lower[$i - 1];
        }

        for ($j = $r - 1; $j < $lowerRounds; $j++) {
            $steps[] = $lower[$j];
        }

        $rounds = [];

        foreach ($steps as $matches) {
            if ($matches > 0) {
                $rounds[] = ['m' => $matches, 'final' => false, 'ifNeeded' => false];
            }
        }

        $grandFinalMatches = $grandFinal === 'skip' ? 0 : 1;

        if ($grandFinalMatches === 1) {
            $rounds[] = ['m' => 1, 'final' => true, 'ifNeeded' => false];
        }

        if ($grandFinal === 'reset') {
            $rounds[] = ['m' => 1, 'final' => true, 'ifNeeded' => true];
        }

        $reset = $grandFinal === 'reset' ? 1 : 0;

        return new Structure($rounds, 2 * $n - 3 + $grandFinalMatches + $reset, 2,
            1 + $lowerRounds + ($reset === 1 ? 2 : $grandFinalMatches), 2, byes: $p - $n);
    }

    public static function roundRobinRounds(int $size): int
    {
        return $size % 2 === 0 ? $size - 1 : $size;
    }

    public function roundRobin(int $n, int $iterations): Structure
    {
        $rounds = [];

        for ($i = 0; $i < $iterations * self::roundRobinRounds($n); $i++) {
            $rounds[] = ['m' => intdiv($n, 2), 'final' => false, 'ifNeeded' => false];
        }

        $games = $iterations * ($n - 1);

        return new Structure($rounds, intdiv($iterations * $n * ($n - 1), 2), $games, $games, $games, byesPerRound: $n % 2);
    }

    public function swiss(int $n, int $rounds): Structure
    {
        $list = [];

        for ($i = 0; $i < $rounds; $i++) {
            $list[] = ['m' => intdiv($n, 2), 'final' => false, 'ifNeeded' => false];
        }

        $fewest = $n % 2 === 1 ? $rounds - 1 : $rounds;

        return new Structure($list, $rounds * intdiv($n, 2), $fewest, $rounds, $fewest, byesPerRound: $n % 2);
    }

    /**
     * Balanced group sizes, bigger groups first.
     *
     * @return non-empty-list<int>
     */
    public static function groups(int $n, int $size): array
    {
        $count = max(1, (int) ceil($n / $size));
        $base = intdiv($n, $count);
        $extra = $n % $count;
        $sizes = [];

        for ($i = 0; $i < $count; $i++) {
            $sizes[] = $base + ($i < $extra ? 1 : 0);
        }

        return $sizes;
    }

    public function twoStage(int $n, FormatOptions $options): Structure
    {
        $sizes = self::groups($n, $options->groupSize);
        $smallest = min($sizes);
        $advance = min($options->advance, $smallest);

        $perGroup = array_map(fn (int $size): Structure => match ($options->groupStage) {
            'round-robin' => $this->roundRobin($size, 1),
            'double-elimination' => $this->doubleElimination($size, 'skip'),
            default => $this->singleElimination($size, false),
        }, $sizes);

        $depth = max(array_map(fn (Structure $group): int => count($group->rounds), $perGroup));
        $rounds = [];

        for ($i = 0; $i < $depth; $i++) {
            $rounds[] = ['m' => array_sum(array_map(fn (Structure $group): int => $group->rounds[$i]['m'] ?? 0, $perGroup)), 'final' => false, 'ifNeeded' => false];
        }

        $finalists = count($sizes) * $advance;
        $final = $options->finalStage === 'double-elimination'
            ? $this->doubleElimination($finalists, 'single')
            : $this->singleElimination($finalists, false);

        $guaranteed = match ($options->groupStage) {
            'round-robin' => $smallest - 1,
            'double-elimination' => 2,
            default => 1,
        };

        return new Structure(
            [...$rounds, ...$final->rounds],
            array_sum(array_map(fn (Structure $group): int => $group->matches, $perGroup)) + $final->matches,
            $guaranteed,
            max(array_map(fn (Structure $group): int => $group->max, $perGroup)) + $final->max,
            $guaranteed,
            groups: $sizes,
            advance: $advance,
            finalists: $finalists,
            groupDepth: $depth,
        );
    }

    public function freeForAll(int $n, int $heatSize, int $advance): Structure
    {
        $rounds = [];
        $left = $n;
        $guard = 0;

        while ($left > $heatSize && $guard < 20) {
            $heats = (int) ceil($left / $heatSize);
            $rounds[] = ['m' => $heats, 'final' => false, 'ifNeeded' => false];
            $left = $heats * $advance;
            $guard++;
        }

        $rounds[] = ['m' => 1, 'final' => true, 'ifNeeded' => false];

        return new Structure($rounds, array_sum(array_column($rounds, 'm')), 1, count($rounds), 1);
    }

    /**
     * Null when the format can run, or the reason it cannot, shown instead of
     * its time bar. The reason is the English copy and its translation key:
     * the estimator stays free of the framework, the page translates.
     */
    public function disabledReason(TournamentFormat $format, GameProfile $profile, int $n): ?string
    {
        return match (true) {
            $format === TournamentFormat::FreeForAll => $profile->isChess()
                ? 'Needs 3 or more players in one match. Chess is always one player against one.'
                : 'Needs 3 or more players in one match. Rocket League is always one team against one.',
            $format === TournamentFormat::Leaderboard => $profile->isChess()
                ? 'Needs a game with a score or time you play alone, like a time trial. Chess games are won against an opponent.'
                : 'Needs a game with a score or time you play alone, like a time trial. A Rocket League series is won against another team.',
            $format === TournamentFormat::Swiss && $n < 4 => 'Needs at least 4 players.',
            $format === TournamentFormat::TwoStage && $n < 6 => 'Needs at least 6 players for two groups.',
            $format === TournamentFormat::DoubleElimination && $n < 3 => 'Needs at least 3 players.',
            $n < 2 => 'Needs at least 2 players.',
            default => null,
        };
    }

    public function structure(TournamentFormat $format, int $n, FormatOptions $options): Structure
    {
        return match ($format) {
            TournamentFormat::SingleElimination => $this->singleElimination($n, $options->thirdPlace),
            TournamentFormat::DoubleElimination => $this->doubleElimination($n, $options->grandFinal),
            TournamentFormat::RoundRobin => $this->roundRobin($n, $options->iterations),
            TournamentFormat::Swiss => $this->swiss($n, $options->swissRounds ?? self::swissDefault($n)),
            TournamentFormat::TwoStage => $this->twoStage($n, $options),
            TournamentFormat::FreeForAll => $this->freeForAll($n, $options->heatSize, $options->heatAdvance),
            TournamentFormat::Leaderboard => new Structure([['m' => $n, 'final' => true, 'ifNeeded' => false]], $n, 1, 3, 1),
        };
    }

    /**
     * Duration in the game's unit. `$stations` null = online, no limit. In
     * daily chess, formats whose pairings don't depend on results (round
     * robin, round-robin groups) start all their games at once.
     */
    public function duration(TournamentFormat $format, Structure $structure, GameProfile $profile, FormatOptions $options, ?int $stations): Duration
    {
        $slot = $profile->slot($options->bestOf);
        $finalSlot = $profile->slot($options->finalBestOf);
        $rounds = array_map(fn (array $round): array => [...$round, 'merged' => 0], $structure->rounds);

        if ($profile->allAtOnce && ($format === TournamentFormat::RoundRobin || ($format === TournamentFormat::TwoStage && $options->groupStage === 'round-robin'))) {
            $depth = $format === TournamentFormat::RoundRobin ? count($rounds) : $structure->groupDepth;
            $merged = ['m' => array_sum(array_column(array_slice($rounds, 0, $depth), 'm')), 'final' => false, 'ifNeeded' => false, 'merged' => $depth];
            $rounds = [$merged, ...array_slice($rounds, $depth)];
        }

        $blocks = [];

        foreach ($rounds as $round) {
            $waves = $stations !== null ? max(1, (int) ceil($round['m'] / $stations)) : 1;
            $blocks[] = [
                't' => $waves * ($round['final'] ? $finalSlot : $slot),
                'm' => $round['m'],
                'waves' => $waves,
                'final' => $round['final'],
                'ifNeeded' => $round['ifNeeded'],
                'merged' => $round['merged'],
            ];
        }

        $play = (float) array_sum(array_column($blocks, 't'));
        $breaks = max(0, count($blocks) - 1) * $profile->break;
        $ifNeeded = 0.0;

        foreach ($blocks as $block) {
            if ($block['ifNeeded']) {
                $ifNeeded += $block['t'] + $profile->break;
            }
        }

        return new Duration($blocks, $play, $breaks, $play + $breaks, $play + $breaks - $ifNeeded);
    }

    /**
     * Swiss gets as many rounds as fit, between the default and
     * min(log2 N + 2, Challonge's maximum) (recommendation rule 2).
     */
    public function swissRoundsThatFit(int $n, GameProfile $profile, FormatOptions $options, ?int $stations, float $window): int
    {
        $low = self::swissDefault($n);
        $high = min(self::log2($n) + 2, self::swissMax($n));
        $best = $low;

        for ($rounds = $low; $rounds <= $high; $rounds++) {
            if ($this->duration(TournamentFormat::Swiss, $this->swiss($n, $rounds), $profile, $options, $stations)->total <= $window) {
                $best = $rounds;
            }
        }

        return $best;
    }

    public static function fit(float $total, float $window): string
    {
        if ($total <= $window) {
            return 'fits';
        }

        return $total <= $window * self::NEAR_MISS ? 'over' : 'long';
    }

    /**
     * Every format for N participants in `$window` (the game's unit).
     * Recommendation among the enabled formats that fit: most guaranteed
     * games, then a format that ends in a final, then the shortest (Rocket
     * League: the final first). Nothing fits: the shortest, the "too many at
     * once" ones last.
     */
    public function evaluate(int $n, GameProfile $profile, FormatOptions $options, ?int $stations, float $window): Evaluation
    {
        if ($profile->isDaily()) {
            $stations = null;
        }

        $swissRounds = $this->swissRoundsThatFit($n, $profile, $options, $stations, $window);
        $used = $options->swissRounds === null
            ? $options->withSwissRounds($swissRounds)
            : $options;

        $rows = [];

        foreach (TournamentFormat::cases() as $format) {
            $reason = $this->disabledReason($format, $profile, $n);

            if ($reason !== null) {
                $rows[] = new EstimateRow($format, false, $reason);

                continue;
            }

            $structure = $this->structure($format, $n, $used);
            $duration = $this->duration($format, $structure, $profile, $used, $stations);
            $atOnce = 0;

            if ($profile->allAtOnce && $format === TournamentFormat::RoundRobin) {
                $atOnce = $structure->guaranteed;
            }

            if ($profile->allAtOnce && $format === TournamentFormat::TwoStage && $used->groupStage === 'round-robin' && $structure->groups !== []) {
                $atOnce = max($structure->groups) - 1;
            }

            $rows[] = new EstimateRow($format, true, '', $structure, $duration, self::fit($duration->total, $window), $atOnce, $atOnce > self::AT_ONCE_MAX);
        }

        $enabled = array_values(array_filter($rows, fn (EstimateRow $row): bool => $row->enabled));
        $fitting = array_values(array_filter($enabled, fn (EstimateRow $row): bool => $row->fit === 'fits' && ! $row->heavy));
        $finalFirst = $profile->isRocketLeague();
        $recommended = null;
        $nothingFits = false;

        if ($fitting !== []) {
            usort($fitting, function (EstimateRow $a, EstimateRow $b) use ($finalFirst): int {
                $final = (int) $b->format->hasFinal() <=> (int) $a->format->hasFinal();
                $guaranteed = $b->guaranteed() <=> $a->guaranteed();

                return ($finalFirst ? ($final ?: $guaranteed) : ($guaranteed ?: $final)) ?: $a->total() <=> $b->total();
            });
            $recommended = $fitting[0]->format;
        } elseif ($enabled !== []) {
            $nothingFits = true;
            usort($enabled, fn (EstimateRow $a, EstimateRow $b): int => ((int) $a->heavy <=> (int) $b->heavy)
                ?: ($a->total() <=> $b->total())
                ?: ($b->guaranteed() <=> $a->guaranteed()));
            $recommended = $enabled[0]->format;
        }

        return new Evaluation($rows, $recommended, $nothingFits, $used->swissRounds ?? $swissRounds, $used);
    }

    /**
     * A duration for people: "1 h 39 min", "48 min"; daily chess in days, weeks or months.
     */
    public static function format(float $value, GameProfile $profile): string
    {
        if ($profile->isDaily()) {
            if ($value < 14) {
                return trans_choice(':count day|:count days', (int) round($value));
            }

            if ($value < 60) {
                return trans_choice(':count week|:count weeks', (int) round($value / 7));
            }

            return trans_choice(':count month|:count months', (int) round($value / 30));
        }

        $hours = (int) floor($value / 60);
        $minutes = (int) round(fmod($value, 60));

        if ($hours === 0) {
            return __(':minutes min', ['minutes' => $minutes]);
        }

        return $minutes === 0 ? __(':hours h', ['hours' => $hours]) : __(':hours h :minutes min', ['hours' => $hours, 'minutes' => $minutes]);
    }
}
