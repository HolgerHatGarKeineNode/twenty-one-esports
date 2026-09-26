<?php

namespace App\Support\Tournaments\Engine;

/**
 * Seeding by Elo: the highest rating is seed 1. Equal ratings (every new
 * player and mix team starts at 1000) are ordered by a hash of the seed and
 * the entrant id, so the order is reproducible from the published seed and
 * nobody can pick a position by signing up early or late.
 */
final class Seeding
{
    /**
     * @param  list<Entrant>  $entrants
     * @return list<Entrant> seed 1 first
     */
    public static function order(array $entrants, string $seed): array
    {
        usort($entrants, fn (Entrant $a, Entrant $b): int => ($b->rating <=> $a->rating)
            ?: strcmp(hash('sha256', $seed.':'.$a->id), hash('sha256', $seed.':'.$b->id))
            ?: $a->id <=> $b->id);

        return $entrants;
    }

    /**
     * Seeds in bracket order for a bracket of `$size` (a power of two):
     * 1 meets `$size`, and 1 and 2 can only meet in the final
     * (8: 1 8 4 5 2 7 3 6).
     *
     * @return list<int> 1-based seeds
     */
    public static function bracketOrder(int $size): array
    {
        $order = [1];

        for ($length = 1; $length < $size; $length *= 2) {
            $next = [];

            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = 2 * $length + 1 - $seed;
            }

            $order = $next;
        }

        return $order;
    }
}
