<?php

namespace App\Support\Tournaments\Engine;

use App\Support\Tournaments\FormatOptions;

/**
 * Who plays where once results come in: fills every slot whose source is
 * decided (a winner, a loser, a heat place, a group place) and says for each
 * match whether it waits for an earlier one, is ready, is done, or is skipped
 * (the grand-final reset when the upper-bracket winner already won).
 *
 * Pure: the bracket and the results in, the state out. A group place is only
 * known once every match of the group is done.
 */
final class Advancement
{
    /**
     * @param  array<string, MatchResult>  $results  by match key
     * @return array<string, array{entrants: list<int|null>, status: 'waiting'|'ready'|'done'|'skipped', winner: int|null, loser: int|null, ranking: list<int>}>
     */
    public static function resolve(Bracket $bracket, array $results, FormatOptions $options): array
    {
        $state = [];
        $groupPlaces = [];

        foreach ($bracket->matches as $match) {
            $entrants = array_map(function (Slot $slot) use (&$state, &$groupPlaces, $bracket, $results, $options): ?int {
                return match ($slot->take) {
                    'entrant' => $slot->entrant,
                    'winner' => $state[(string) $slot->match]['winner'] ?? null,
                    'loser' => $state[(string) $slot->match]['loser'] ?? null,
                    'rank' => $state[(string) $slot->match]['ranking'][$slot->rank - 1] ?? null,
                    default => ($groupPlaces[(int) $slot->group] ??= self::groupPlaces($bracket, (int) $slot->group, $state, $results, $options))[$slot->rank - 1] ?? null,
                };
            }, $match->slots);

            $known = ! in_array(null, $entrants, true);
            $result = $results[$match->key] ?? null;

            if ($match->ifNeeded && self::resetNotNeeded($match, $state)) {
                $state[$match->key] = ['entrants' => $entrants, 'status' => 'skipped', 'winner' => null, 'loser' => null, 'ranking' => []];

                continue;
            }

            if ($known && $result !== null) {
                $ranking = self::ranking($entrants, $result);
                $state[$match->key] = [
                    'entrants' => $entrants,
                    'status' => 'done',
                    'winner' => $result->winner === null ? null : $entrants[$result->winner],
                    'loser' => $result->winner === null || count($entrants) !== 2 ? null : $entrants[1 - $result->winner],
                    'ranking' => $ranking,
                ];

                continue;
            }

            $state[$match->key] = ['entrants' => $entrants, 'status' => $known ? 'ready' : 'waiting', 'winner' => null, 'loser' => null, 'ranking' => []];
        }

        return $state;
    }

    /**
     * The reset is not needed when the grand final is decided and its winner
     * came from the upper bracket (slot 0 of the grand final, unbeaten so far).
     *
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     */
    private static function resetNotNeeded(BracketMatch $reset, array $state): bool
    {
        $grandFinal = $state[(string) $reset->slots[0]->match] ?? null;

        return $grandFinal !== null && $grandFinal['status'] === 'done' && $grandFinal['winner'] === $grandFinal['entrants'][0];
    }

    /**
     * Entrants of a finished match by place: the ranks of a heat, else winner first.
     *
     * @param  list<int|null>  $entrants
     * @return list<int>
     */
    private static function ranking(array $entrants, MatchResult $result): array
    {
        if ($result->ranks !== null) {
            $order = array_keys($entrants);
            usort($order, fn (int $a, int $b): int => ($result->ranks[$a] ?? PHP_INT_MAX) <=> ($result->ranks[$b] ?? PHP_INT_MAX) ?: $a <=> $b);

            return array_map(fn (int $index): int => (int) $entrants[$index], $order);
        }

        if ($result->winner !== null && count($entrants) === 2) {
            return [(int) $entrants[$result->winner], (int) $entrants[1 - $result->winner]];
        }

        return array_map(fn (?int $entrant): int => (int) $entrant, $entrants);
    }

    /**
     * Places of one group once all its matches are done, else an empty list.
     * Round-robin groups by their table; bracket groups by how far each got:
     * never knocked out first (fewer losses first), then the later knock-outs.
     *
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @param  array<string, MatchResult>  $results
     * @return list<int>
     */
    private static function groupPlaces(Bracket $bracket, int $group, array $state, array $results, FormatOptions $options): array
    {
        $members = $bracket->groups[$group] ?? [];
        $matches = array_values(array_filter($bracket->matches, fn (BracketMatch $match): bool => $match->group === $group && $match->stage === 1));

        foreach ($matches as $match) {
            if (($state[$match->key]['status'] ?? null) !== 'done') {
                return [];
            }
        }

        if ($options->groupStage === 'round-robin') {
            $games = [];

            foreach ($matches as $match) {
                $entrants = $state[$match->key]['entrants'];
                $games[] = [(int) $entrants[0], (int) $entrants[1], $results[$match->key]];
            }

            $win = $options->rankBy === 'custom' ? $options->pointsWin : 1.0;
            $tie = $options->rankBy === 'custom' ? $options->pointsTie : 0.5;

            return array_column(Standings::table($members, $games, $win, $tie, 0.0, $options->rankBy, $options->roundRobinTieBreaks), 'entrant');
        }

        $losses = array_fill_keys($members, 0);
        $out = [];
        $lastLossAt = [];

        foreach ($matches as $match) {
            $loser = $state[$match->key]['loser'];

            if ($loser === null) {
                continue;
            }

            $losses[$loser]++;
            $lastLossAt[$loser] = $match->round;

            if ($options->groupStage === 'single-elimination' || $match->bracket === 'lower') {
                $out[$loser] = $match->round;
            }
        }

        $seeds = array_flip($members);
        $ordered = $members;
        usort($ordered, function (int $a, int $b) use ($out, $losses, $seeds, $lastLossAt): int {
            $aOut = $out[$a] ?? PHP_INT_MAX;
            $bOut = $out[$b] ?? PHP_INT_MAX;

            return ($bOut <=> $aOut)
                ?: ($losses[$a] <=> $losses[$b])
                ?: (($lastLossAt[$b] ?? 0) <=> ($lastLossAt[$a] ?? 0))
                ?: ($seeds[$a] <=> $seeds[$b]);
        });

        return $ordered;
    }
}
