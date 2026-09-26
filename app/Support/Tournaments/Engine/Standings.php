<?php

namespace App\Support\Tournaments\Engine;

/**
 * The table of a round robin, a Swiss tournament or one group, with the
 * organizer's ranking and up to three tie-breaks in order (Challonge's list,
 * TOURNAMENT-FORMATS.md, section 1). Whatever is still tied after them is
 * ordered by seed, so the table is always complete and reproducible.
 *
 * `$games` are the finished matches: `[a, b, result]` with slot 0 = a;
 * `b` null is a bye for `a` (Swiss), worth the bye points.
 *
 * Median Buchholz: the sum of the opponents' points without the best and the
 * worst of them (with fewer than three opponents, the plain sum). A bye is
 * no opponent and adds nothing.
 */
final class Standings
{
    /**
     * @param  list<int>  $entrants  seed 1 first
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games
     * @param  'points'|'match-wins'|'game-wins'|'custom'  $rankBy
     * @param  list<string>  $tieBreaks
     * @return list<StandingRow> first place first
     */
    public static function table(array $entrants, array $games, float $win = 1.0, float $tie = 0.5, float $bye = 1.0, string $rankBy = 'points', array $tieBreaks = []): array
    {
        /** @var array<int, StandingRow> $rows */
        $rows = [];

        foreach ($entrants as $seed => $entrant) {
            $rows[$entrant] = new StandingRow($entrant, $seed);
        }

        foreach ($games as [$a, $b, $result]) {
            if (! isset($rows[$a])) {
                continue;
            }

            if ($b === null) {
                $rows[$a]->byes++;
                $rows[$a]->points += $bye;

                continue;
            }

            if (! isset($rows[$b])) {
                continue;
            }

            foreach ([[$rows[$a], 0, $b], [$rows[$b], 1, $a]] as [$row, $slot, $opponent]) {
                $row->opponents[] = $opponent;
                $row->gameWins += $result->games[$slot] ?? 0.0;
                $row->gameLosses += $result->games[1 - $slot] ?? 0.0;
                $row->pointsFor += $result->points[$slot] ?? 0.0;
                $row->pointsAgainst += $result->points[1 - $slot] ?? 0.0;

                if ($result->winner === null) {
                    $row->ties++;
                    $row->points += $tie;
                } elseif ($result->winner === $slot) {
                    $row->wins++;
                    $row->points += $win;
                } else {
                    $row->losses++;
                }
            }
        }

        foreach ($rows as $row) {
            $scores = array_map(fn (int $opponent): float => $rows[$opponent]->points, $row->opponents);
            sort($scores);
            $row->buchholz = array_sum($scores);
            $row->medianBuchholz = count($scores) >= 3 ? array_sum(array_slice($scores, 1, -1)) : array_sum($scores);
        }

        $primary = match ($rankBy) {
            'match-wins', 'game-wins' => $rankBy,
            default => 'points',
        };

        $ordered = self::rank(array_values($rows), [$primary, ...$tieBreaks], $games);

        foreach ($ordered as $index => $row) {
            $row->rank = $index + 1;
        }

        return $ordered;
    }

    /**
     * Sort by the first criterion, split the ties, and sort each tie by the
     * next criterion. Head-to-head only counts games among the tied entrants.
     *
     * @param  list<StandingRow>  $rows
     * @param  list<string>  $criteria
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games
     * @return list<StandingRow>
     */
    private static function rank(array $rows, array $criteria, array $games): array
    {
        if (count($rows) <= 1 || $criteria === []) {
            usort($rows, fn (StandingRow $a, StandingRow $b): int => $a->seed <=> $b->seed);

            return $rows;
        }

        $criterion = array_shift($criteria);
        $tied = array_map(fn (StandingRow $row): int => $row->entrant, $rows);
        $values = [];

        foreach ($rows as $row) {
            $values[$row->entrant] = round(self::value($criterion, $row, $tied, $games), 6);
        }

        usort($rows, fn (StandingRow $a, StandingRow $b): int => $values[$b->entrant] <=> $values[$a->entrant]);

        $ranked = [];
        $group = [];
        $current = null;

        foreach ($rows as $row) {
            if ($current !== null && $values[$row->entrant] !== $current) {
                array_push($ranked, ...self::rank($group, $criteria, $games));
                $group = [];
            }

            $current = $values[$row->entrant];
            $group[] = $row;
        }

        array_push($ranked, ...self::rank($group, $criteria, $games));

        return $ranked;
    }

    /**
     * @param  list<int>  $tied
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games
     */
    private static function value(string $criterion, StandingRow $row, array $tied, array $games): float
    {
        $played = $row->gameWins + $row->gameLosses;

        return match ($criterion) {
            'points' => $row->points,
            'match-wins' => (float) $row->wins,
            'game-wins' => $row->gameWins,
            'game-win-percentage' => $played > 0 ? $row->gameWins / $played : 0.0,
            'game-difference' => $row->gameWins - $row->gameLosses,
            'points-scored' => $row->pointsFor,
            'points-difference' => $row->pointsFor - $row->pointsAgainst,
            'median-buchholz' => $row->medianBuchholz,
            'head-to-head' => self::headToHead($row->entrant, $tied, $games),
            default => 0.0,
        };
    }

    /**
     * Match wins against the other tied entrants (a draw counts half).
     *
     * @param  list<int>  $tied
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games
     */
    private static function headToHead(int $entrant, array $tied, array $games): float
    {
        $wins = 0.0;

        foreach ($games as [$a, $b, $result]) {
            if ($b === null || ($a !== $entrant && $b !== $entrant)) {
                continue;
            }

            $opponent = $a === $entrant ? $b : $a;

            if (! in_array($opponent, $tied, true)) {
                continue;
            }

            $slot = $a === $entrant ? 0 : 1;
            $wins += $result->winner === null ? 0.5 : ($result->winner === $slot ? 1.0 : 0.0);
        }

        return $wins;
    }
}
