<?php

namespace App\Support\Tournaments\Engine;

/**
 * Swiss pairings, one round at a time (the next round is only known once the
 * one before is done).
 *
 * Order: points, then seed. With an odd field the lowest-placed entrant who
 * has not had a bye yet gets it (a bye match with one side, worth the bye
 * points). Then from the top: each entrant meets the one half a score group
 * below (Dutch system: 1 against N/2 + 1 in round 1), never somebody met
 * before; if that leaves nobody for a later entrant, the pairing backtracks.
 * Only when no pairing without a rematch exists at all (more rounds than the
 * field allows) are rematches allowed. Sides: whoever had slot 0 (White)
 * less often gets it; on a tie the higher-placed entrant alternates by round.
 */
final class Swiss
{
    /** Backtracking steps before a round gives up and allows rematches. */
    private const BUDGET = 200_000;

    private int $steps = 0;

    /**
     * @param  array<int, float>  $points
     * @param  array<int, array<int, bool>>  $met
     */
    private function __construct(
        private readonly array $points,
        private readonly array $met,
    ) {}

    /**
     * @param  list<int>  $entrants  seed 1 first
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games  the finished matches and byes of earlier rounds
     * @return list<BracketMatch> the matches of `$round`, a bye (one slot) last
     */
    public static function pairRound(int $round, array $entrants, array $games, float $win = 1.0, float $tie = 0.5, float $bye = 1.0, string $prefix = '', int $stage = 1): array
    {
        $table = Standings::table($entrants, $games, $win, $tie, $bye);
        $seeds = array_flip($entrants);
        $points = array_column($table, 'points', 'entrant');

        // Points, then seed: tie-breaks decide the final table, not who meets whom.
        $order = $entrants;
        usort($order, fn (int $a, int $b): int => ($points[$b] <=> $points[$a]) ?: ($seeds[$a] <=> $seeds[$b]));

        $met = [];
        $byes = [];
        $home = [];

        foreach ($games as [$a, $b]) {
            if ($b === null) {
                $byes[$a] = true;

                continue;
            }

            $met[$a][$b] = true;
            $met[$b][$a] = true;
            $home[$a] = ($home[$a] ?? 0) + 1;
        }

        $byeEntrant = null;

        if (count($order) % 2 === 1) {
            foreach (array_reverse($order) as $candidate) {
                if (! isset($byes[$candidate])) {
                    $byeEntrant = $candidate;

                    break;
                }
            }

            $byeEntrant ??= $order[count($order) - 1];
            $order = array_values(array_filter($order, fn (int $entrant): bool => $entrant !== $byeEntrant));
        }

        $pairs = (new self($points, $met))->pair($order) ?? (new self($points, []))->pair($order) ?? [];

        $matches = [];

        foreach ($pairs as $index => [$a, $b]) {
            // `$a` is placed higher. Fewer home games gets slot 0; a tie alternates by round.
            $aHome = $home[$a] ?? 0;
            $bHome = $home[$b] ?? 0;
            $aFirst = $aHome === $bHome ? ($round + $index) % 2 === 1 : $aHome < $bHome;
            $matches[] = new BracketMatch("{$prefix}s{$round}-".($index + 1), $stage, null, 'main', $round, $index + 1,
                $aFirst ? [Slot::entrant($a), Slot::entrant($b)] : [Slot::entrant($b), Slot::entrant($a)]);
        }

        if ($byeEntrant !== null) {
            $matches[] = new BracketMatch("{$prefix}s{$round}-bye", $stage, null, 'bye', $round, count($pairs) + 1, [Slot::entrant($byeEntrant)]);
        }

        return $matches;
    }

    /**
     * @param  list<int>  $order  placed highest first
     * @return list<array{0: int, 1: int}>|null
     */
    private function pair(array $order): ?array
    {
        if ($order === []) {
            return [];
        }

        if (++$this->steps > self::BUDGET) {
            return null;
        }

        $top = $order[0];
        $rest = array_slice($order, 1);

        foreach (self::candidates($top, $rest, $this->points) as $candidate) {
            if (isset($this->met[$top][$candidate])) {
                continue;
            }

            $paired = $this->pair(array_values(array_filter($rest, fn (int $entrant): bool => $entrant !== $candidate)));

            if ($paired !== null) {
                return [[$top, $candidate], ...$paired];
            }

            if ($this->exhausted()) {
                return null;
            }
        }

        return null;
    }

    private function exhausted(): bool
    {
        return $this->steps > self::BUDGET;
    }

    /**
     * Opponents for the top entrant, best first: the score group from its
     * middle down (Dutch), then the group's upper part, then lower groups in order.
     *
     * @param  list<int>  $rest
     * @param  array<int, float>  $points
     * @return list<int>
     */
    private static function candidates(int $top, array $rest, array $points): array
    {
        $group = array_values(array_filter($rest, fn (int $entrant): bool => $points[$entrant] === $points[$top]));
        $below = array_values(array_filter($rest, fn (int $entrant): bool => $points[$entrant] !== $points[$top]));
        // The group including `$top` has count($group) + 1 members; its middle is at half of that.
        $middle = max(0, intdiv(count($group) + 1, 2) - 1);

        return [...array_slice($group, $middle), ...array_reverse(array_slice($group, 0, $middle)), ...$below];
    }
}
