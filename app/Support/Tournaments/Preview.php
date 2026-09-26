<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\Elimination;
use App\Support\Tournaments\Engine\Entrant;
use App\Support\Tournaments\Engine\RoundRobin;
use App\Support\Tournaments\Engine\Seeding;
use App\Support\Tournaments\Engine\Slot;
use App\Support\Tournaments\Engine\Swiss;

/**
 * The animated mini preview of the format chooser (`tfPreview` of the
 * artboard script): SVG boxes, one per match, that light up round by round.
 * Built from the real engine for the entered count: which first-round places
 * are byes, how many lower-bracket matches each round keeps, the round of
 * every round-robin pairing, the groups of a two-stage tournament.
 *
 * Returns primitives in a `$width` × `$height` box; `delay` is the animation
 * start in seconds. Texts are English copy and translation keys.
 */
final class Preview
{
    /** @var list<array{x: float, y: float, w: float, h: float, delay: float, ghost: bool}> */
    private array $rects = [];

    /** @var list<array{d: string, delay: float}> */
    private array $lines = [];

    /** @var list<array{x: float, y: float, text: string, anchor: string, replace: array<string, int|string>}> */
    private array $texts = [];

    private function __construct(private readonly float $width, private readonly float $height) {}

    /**
     * @return array{rects: list<array{x: float, y: float, w: float, h: float, delay: float, ghost: bool}>, lines: list<array{d: string, delay: float}>, texts: list<array{x: float, y: float, text: string, anchor: string, replace: array<string, int|string>}>}
     */
    public static function for(TournamentFormat $format, int $n, FormatOptions $options, float $width = 480, float $height = 208): array
    {
        $preview = new self($width, $height);
        $n = max(2, min(64, $n));

        match ($format) {
            TournamentFormat::SingleElimination => $preview->single($n, $options),
            TournamentFormat::DoubleElimination => $preview->double($n, $options),
            TournamentFormat::RoundRobin => $preview->roundRobin($n),
            TournamentFormat::TwoStage => $preview->twoStage($n, $options),
            TournamentFormat::Swiss => $preview->swiss($n, $options),
            default => $preview->board(),
        };

        return ['rects' => $preview->rects, 'lines' => $preview->lines, 'texts' => $preview->texts];
    }

    private function single(int $n, FormatOptions $options): void
    {
        $tree = $this->tree($n, 0, $this->width, 16, $this->height - ($options->thirdPlace ? 30 : 4), 0);
        $this->text(0, 10, 'Round 1');
        $this->text($tree['lastX'], 10, 'Final', 'end');

        $third = collect(Elimination::single(self::slots($n), true))->contains(fn (BracketMatch $match): bool => $match->bracket === 'third-place');

        if ($options->thirdPlace && $third) {
            $this->rect($tree['lastX'] - $tree['box'], $this->height - 18, $tree['box'], 12, $tree['rounds'] - 1);
            $this->text($tree['lastX'] - $tree['box'] - 6, $this->height - 8, '3rd place', 'end');
        }
    }

    private function double(int $n, FormatOptions $options): void
    {
        $bracket = Elimination::double(self::slots($n), $options->grandFinal, $options->split);
        $lower = [];

        foreach ($bracket as $match) {
            if ($match->bracket === 'lower' && preg_match('/^l(\d+)-/', $match->key, $found) === 1) {
                $lower[(int) $found[1]] = ($lower[(int) $found[1]] ?? 0) + 1;
            }
        }

        $lowerRounds = 2 * (Estimator::log2($n) - 1);
        $middle = $this->height * 0.5;
        $this->tree($n, 0, $this->width * 0.56, 18, $middle - 6, 0, $options->split);
        $this->text(0, 10, 'Upper bracket');
        $this->text(0, $middle + 12, 'Lower bracket');
        $column = ($this->width * 0.8 - $this->width * 0.06) / max(1, $lowerRounds);
        $box = min(40, $column - 8);

        for ($j = 1; $j <= $lowerRounds; $j++) {
            $count = $lower[$j] ?? 0;

            if ($count === 0) {
                continue;
            }

            $top = $middle + 22;
            $slot = ($this->height - 4 - $top) / $count;
            $boxHeight = max(3, min(12, $slot - 3));

            for ($i = 0; $i < $count; $i++) {
                $this->rect($this->width * 0.06 + ($j - 1) * $column, $top + ($i + 0.5) * $slot - $boxHeight / 2, $box, $boxHeight, $j);
            }
        }

        $x = $this->width - 52;
        $y = $this->height * 0.5 - 8;

        if ($options->grandFinal !== 'skip') {
            $this->rect($x, $y, 48, 16, $lowerRounds + 1);
            $this->text($x + 48, $y - 6, 'Grand final', 'end');
        }

        if ($options->grandFinal === 'reset') {
            $this->rect($x, $y + 24, 48, 16, $lowerRounds + 2, true);
            $this->text($x + 48, $y + 54, 'if needed', 'end');
        }
    }

    private function roundRobin(int $n): void
    {
        $shown = min($n, 16);
        $this->grid([$shown], $this->width, 12, true);
        $this->text(0, 10, $n > 16 ? 'First 16 of :n shown' : ':n × :n, every square is one match', 'start', ['n' => $n]);
    }

    private function twoStage(int $n, FormatOptions $options): void
    {
        $bracket = BracketBuilder::build(TournamentFormat::TwoStage, array_map(fn (int $id): Entrant => new Entrant($id), range(1, $n)), $options, 'preview');
        $sizes = array_values(array_map('count', $bracket->groups));

        if ($sizes === []) {
            return;
        }

        $last = $this->grid($sizes, $this->width * 0.5, 16, false);
        $this->text(0, 10, ':count groups', 'start', ['count' => count($sizes)]);
        $finalists = count($sizes) * min($options->advance, min($sizes));
        $this->tree($finalists, $this->width * 0.58, $this->width, 18, $this->height - 4, $last + 1);
        $this->text($this->width * 0.58, 10, 'Final stage, top :count of each group', 'start', ['count' => min($options->advance, min($sizes))]);
    }

    private function swiss(int $n, FormatOptions $options): void
    {
        $rounds = $options->swissRounds ?? Estimator::swissDefault($n);
        $first = Swiss::pairRound(1, range(1, $n), []);
        $pairs = count(array_filter($first, fn (BracketMatch $match): bool => $match->bracket !== 'bye'));
        $odd = $n % 2;
        $column = $this->width / $rounds;
        $box = min(44, $column - 10);

        for ($r = 0; $r < $rounds; $r++) {
            $groups = $r === 0 ? [$pairs] : self::scoreGroups($r, $pairs);
            $slots = $pairs + $odd;
            $y = 18;
            $slot = ($this->height - 22 - (count($groups) - 1) * 5) / $slots;
            $boxHeight = max(2, min(14, $slot - 3));

            foreach ($groups as $size) {
                for ($i = 0; $i < $size; $i++) {
                    $this->rect($r * $column, $y + ($slot - $boxHeight) / 2, $box, $boxHeight, $r);
                    $y += $slot;
                }

                $y += 5;
            }

            if ($odd === 1) {
                $this->rect($r * $column, $y - 5 + ($slot - $boxHeight) / 2, $box, $boxHeight, $r, true);
            }

            if ($rounds <= 9) {
                $this->text($r * $column, 10, 'R:round', 'start', ['round' => $r + 1]);
            }
        }
    }

    private function board(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->rect(24, 16 + $i * 23, ($this->width - 40) * (1 - $i * 0.1), 14, $i * 0.4);
            $this->text(0, 27 + $i * 23, (string) ($i + 1));
        }
    }

    /**
     * A single-elimination tree; the first-round byes come from the engine.
     *
     * @return array{lastX: float, rounds: int, box: float}
     */
    private function tree(int $n, float $x0, float $x1, float $y0, float $y1, float $step0, bool $noFirstRound = false): array
    {
        $rounds = Estimator::log2($n);
        $size = 1 << $rounds;
        $column = ($x1 - $x0) / max(1, $rounds);
        $box = min(58, $column - 18);

        // Which first-round places hold a match in the engine's bracket; the others are byes.
        $order = Seeding::bracketOrder($size);
        $pairOf = [];

        for ($i = 0; $i < $size; $i += 2) {
            $pairOf[$order[$i]] = intdiv($i, 2);
        }

        $played = [];

        foreach ($noFirstRound ? [] : Elimination::single(self::slots($n), false) as $match) {
            if ($match->round === 1) {
                $played[$pairOf[(int) $match->slots[0]->entrant]] = true;
            }
        }

        $centers = [];

        for ($r = 0; $r < $rounds; $r++) {
            $slots = $size >> ($r + 1);
            $slotHeight = ($y1 - $y0) / $slots;
            $boxHeight = max(3, min(16, $slotHeight - 4));

            for ($j = 0; $j < $slots; $j++) {
                $center = $y0 + ($j + 0.5) * $slotHeight;
                $x = $x0 + $r * $column;
                $this->rect($x, $center - $boxHeight / 2, $box, $boxHeight, $step0 + $r, $r === 0 && ! isset($played[$j]));

                if ($r > 0) {
                    $a = $centers[($r - 1).'-'.(2 * $j)] ?? $center;
                    $b = $centers[($r - 1).'-'.(2 * $j + 1)] ?? $center;
                    $edge = $x0 + ($r - 1) * $column + $box;
                    $mid = $edge + ($column - $box) / 2;
                    $this->lines[] = ['d' => sprintf('M%.1f %.1fH%.1fV%.1fH%.1fM%.1f %.1fH%.1f', $edge, $a, $mid, $b, $edge, $mid, $center, $x), 'delay' => round(($step0 + $r - 1) * 0.32 + 0.16, 2)];
                }

                $centers[$r.'-'.$j] = $center;
            }
        }

        return ['lastX' => $x0 + ($rounds - 1) * $column + $box, 'rounds' => $rounds, 'box' => $box];
    }

    /**
     * Round-robin grids, one square per pairing, lit in the round the engine schedules it.
     *
     * @param  list<int>  $groups
     * @return int the last round
     */
    private function grid(array $groups, float $area, int $maxCell, bool $single): int
    {
        if ($groups === []) {
            return 0;
        }

        $perRow = $single ? 1 : (int) ceil(sqrt(count($groups)));
        $rows = (int) ceil(count($groups) / $perRow);
        $largest = max($groups);
        $cell = max(4, min($maxCell, (int) floor(min(($area - 12 * ($perRow - 1)) / ($perRow * $largest), ($this->height - 24 - 12 * ($rows - 1)) / ($rows * $largest)))));
        $last = 0;

        foreach ($groups as $index => $members) {
            $round = [];

            foreach (RoundRobin::schedule(self::slots($members)) as $match) {
                $a = (int) $match->slots[0]->entrant;
                $b = (int) $match->slots[1]->entrant;
                $round[min($a, $b).'-'.max($a, $b)] = $match->round - 1;
            }

            $gx = ($index % $perRow) * ($largest * $cell + 12);
            $gy = 18 + intdiv($index, $perRow) * ($largest * $cell + 12);

            for ($i = 1; $i <= $members; $i++) {
                for ($j = 1; $j <= $members; $j++) {
                    if ($i === $j) {
                        continue;
                    }

                    $played = $round[min($i, $j).'-'.max($i, $j)];
                    $last = max($last, $played);
                    $this->rect($gx + ($j - 1) * $cell, $gy + ($i - 1) * $cell, $cell - 2, $cell - 2, $single ? $played * min(1, 12 / max(1, $members)) : $played);
                }
            }
        }

        return $last;
    }

    /**
     * `$total` pairs split into `$round` + 1 score groups of binomial shape.
     *
     * @return list<int>
     */
    private static function scoreGroups(int $round, int $total): array
    {
        $weights = [];

        for ($i = 0; $i <= $round; $i++) {
            $weight = 1.0;

            for ($k = 0; $k < $i; $k++) {
                $weight = $weight * ($round - $k) / ($k + 1);
            }

            $weights[] = $weight;
        }

        $sum = array_sum($weights);
        $left = $total;
        $groups = [];

        foreach ($weights as $i => $weight) {
            $size = min($i === $round ? $left : (int) round($total * $weight / $sum), $left);
            $groups[] = $size;
            $left -= $size;
        }

        return array_values(array_filter($groups, fn (int $size): bool => $size > 0));
    }

    /**
     * @return list<Slot>
     */
    private static function slots(int $n): array
    {
        return array_map(Slot::entrant(...), range(1, $n));
    }

    private function rect(float $x, float $y, float $w, float $h, float $step, bool $ghost = false): void
    {
        $this->rects[] = ['x' => round($x, 1), 'y' => round($y, 1), 'w' => round(max(2, $w), 1), 'h' => round(max(2, $h), 1), 'delay' => round($step * 0.32, 2), 'ghost' => $ghost];
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function text(float $x, float $y, string $text, string $anchor = 'start', array $replace = []): void
    {
        $this->texts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'text' => $text, 'anchor' => $anchor, 'replace' => $replace];
    }
}
