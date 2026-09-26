<?php

namespace App\Support\Tournaments\Engine;

/**
 * Everyone against everyone, by the circle method: seed 1 stays, the others
 * turn one place per round. An odd field gets an empty place, so every round
 * one entrant has a bye (no match). Each further iteration repeats the
 * schedule with the sides swapped (in chess: the other color).
 */
final class RoundRobin
{
    /**
     * @param  list<Slot>  $seeds  seed 1 first
     * @return list<BracketMatch>
     */
    public static function schedule(array $seeds, int $iterations = 1, string $prefix = '', int $stage = 1, ?int $group = null): array
    {
        $places = $seeds;

        if (count($places) % 2 === 1) {
            $places[] = null;
        }

        $size = count($places);
        $matches = [];

        if ($size < 2) {
            return [];
        }

        $round = 0;

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $circle = $places;

            for ($r = 0; $r < $size - 1; $r++) {
                $round++;
                $position = 0;

                for ($i = 0; $i < intdiv($size, 2); $i++) {
                    $home = $circle[$i];
                    $away = $circle[$size - 1 - $i];

                    if ($home === null || $away === null) {
                        continue;
                    }

                    // Alternate the fixed seed's side each round, and swap every side on even iterations.
                    $swap = (($i === 0 && $r % 2 === 1) xor ($iteration % 2 === 0));
                    $position++;
                    $matches[] = new BracketMatch("{$prefix}rr{$round}-{$position}", $stage, $group, 'main', $round, $position,
                        $swap ? [$away, $home] : [$home, $away]);
                }

                // Turn: keep the first place, move the last one to the second place.
                $last = array_pop($circle);
                array_splice($circle, 1, 0, [$last]);
            }
        }

        return $matches;
    }
}
