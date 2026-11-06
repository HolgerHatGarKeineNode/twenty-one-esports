<?php

namespace App\Support\Payouts;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\FirstPlaceTie;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentBrackets;

/**
 * The final places of a finished tournament (P9), read from the stored
 * bracket and results with the engine, like
 * App\Support\Tournaments\TournamentChampion reads its winner (place 1 here
 * is always that champion; tests/Feature/Payouts/TournamentPlacementsTest.php
 * checks it per format):
 *
 * - a final stage that ends in one match between two sides (single or double
 *   elimination): the winner first, then everyone else of the final stage by
 *   how late they were knocked out: the later their last match, the better;
 *   in the same time step the final ranks before the match for third place,
 *   and a win before a loss. Sides knocked out in the same step share a place
 *   (the two losing semi-finalists without a match for third place are both
 *   third, and the next place is fifth);
 * - a final stage that ends in one heat (Free for All, Leaderboard): the
 *   heat's ranking, without the entries its result names `unplaced` (a score
 *   leaderboard's entries without a valid value, App\Support\Scores);
 * - a lobby tournament (P10, App\Support\Tournaments\Lobbies), one round
 *   of one or more lobbies: the places inside each lobby, ties kept (the
 *   allies who share place 1), and across lobbies the same place shared:
 *   every lobby's winners are place 1 together, then the next place number
 *   of any lobby, and so on; `unplaced` entries are left out;
 * - a final stage that is a table (round robin, Swiss): the table's ranks;
 *   a tie for place 1 nothing splits shared where the organizer chose so
 *   (FirstPlaceTie), every other tie in seed order;
 * - a table whose tie for place 1 was played off (FirstPlaceTie, stage 2):
 *   the tied by the knockout, everyone else by the table after them.
 *
 * Only sides of the final stage are placed. Null while the tournament is not
 * finished or when its end cannot be read (parallel heats that feed nothing
 * else are read as lobbies).
 */
final class TournamentPlacements
{
    /** How a last match ranks within one time step: the final before the match for third place. */
    private const PRIORITY = ['reset' => 5, 'grand-final' => 5, 'main' => 4, 'heat' => 4, 'upper' => 3, 'lower' => 2, 'third-place' => 1];

    public function __construct(private TournamentBrackets $brackets) {}

    /**
     * @return list<array{place: int, participants: list<int>}>|null participant ids per place, best first
     */
    public function of(Tournament $tournament): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        $bracket = $this->brackets->load($tournament);

        if ($bracket->matches === []) {
            return null;
        }

        $results = $this->brackets->results($tournament);
        $options = $tournament->formatOptions();
        $state = Advancement::resolve($bracket, $results, $options);
        $stage = max(array_map(fn (BracketMatch $match): int => $match->stage, $bracket->matches));
        $fed = [];

        foreach ($bracket->matches as $match) {
            foreach ($match->slots as $slot) {
                if ($slot->match !== null) {
                    $fed[$slot->match] = true;
                }
            }
        }

        $final = array_values(array_filter($bracket->matches, fn (BracketMatch $match): bool => $match->stage === $stage));
        $terminal = array_values(array_filter($final, fn (BracketMatch $match): bool => ! isset($fed[$match->key]) && $match->bracket !== 'third-place'));

        // Lobbies (P10): every heat ends the tournament for its players.
        if (Lobbies::isLobby($tournament) && $terminal !== [] && array_all($terminal, fn (BracketMatch $match): bool => $match->bracket === 'heat')) {
            return self::lobbies($tournament, $terminal, $state);
        }

        // A table's tie for place 1 played off in a knockout (FirstPlaceTie, stage 2): the knockout places the tied, the
        // table everyone else after them.
        if (FirstPlaceTie::isPlayoffStage($tournament, $stage)) {
            return $this->playoff($tournament, $bracket->matches, $final, $terminal, $state, $results);
        }

        if (count($terminal) === 1) {
            $last = $state[$terminal[0]->key] ?? null;

            if (($last['status'] ?? null) === 'skipped') {
                $last = $state[(string) $terminal[0]->slots[0]->match] ?? null;
            }

            if (($last['status'] ?? null) !== 'done') {
                return null;
            }

            // A heat or a leaderboard ranks all its entries, also with only two (a score leaderboard, plan "AoE2 und
            // Trackmania", P4, can have two entries); the entries without a valid value stay unplaced.
            if (count($terminal[0]->slots) > 2 || in_array($terminal[0]->bracket, ['heat', 'board'], true)) {
                $stored = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', $terminal[0]->key)->first()?->result;
                $unplaced = array_map(intval(...), (array) ($stored['unplaced'] ?? []));
                $ranking = array_values(array_filter($last['ranking'], fn (int $id): bool => ! in_array($id, $unplaced, true)));

                return self::numbered(array_map(fn (int $id): array => [$id], $ranking));
            }

            $champion = $last['ranking'][0] ?? $last['winner'] ?? null;

            return $champion === null ? null : self::numbered(self::byKnockOut($final, $state, (int) $champion));
        }

        $groups = $this->table($tournament, $final, $state, $results);

        return $groups === null ? null : self::numbered($groups);
    }

    /**
     * The places of a round robin or Swiss whose tie for place 1 was played off (FirstPlaceTie): the knockout's order
     * for the tied, then the table's for everyone else; null until the knockout has its winner.
     *
     * @param  list<BracketMatch>  $matches  every match of the tournament
     * @param  list<BracketMatch>  $playoff  the knockout's
     * @param  list<BracketMatch>  $terminal  the knockout's last match
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @param  array<string, MatchResult>  $results
     * @return list<array{place: int, participants: list<int>}>|null
     */
    private function playoff(Tournament $tournament, array $matches, array $playoff, array $terminal, array $state, array $results): ?array
    {
        $last = count($terminal) === 1 ? ($state[$terminal[0]->key] ?? null) : null;
        $champion = ($last['status'] ?? null) === 'done' ? ($last['winner'] ?? null) : null;
        $table = $this->table($tournament, array_values(array_filter($matches, fn (BracketMatch $match): bool => $match->stage === 1)), $state, $results);

        if ($champion === null || $table === null) {
            return null;
        }

        $top = self::byKnockOut($playoff, $state, (int) $champion);
        $placed = array_merge(...$top);
        $rest = array_values(array_filter(array_map(fn (array $group): array => array_values(array_diff($group, $placed)), $table)));

        return self::numbered([...$top, ...$rest]);
    }

    /**
     * @param  list<BracketMatch>  $matches
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @return list<list<int>> participant ids per place, best first
     */
    private static function byKnockOut(array $matches, array $state, int $champion): array
    {
        $last = [];

        foreach ($matches as $match) {
            $row = $state[$match->key] ?? null;

            if (($row['status'] ?? null) !== 'done') {
                continue;
            }

            foreach ($row['entrants'] as $entrant) {
                if ($entrant === null || $entrant === $champion) {
                    continue;
                }

                $key = [$match->round, self::PRIORITY[$match->bracket] ?? 0, $row['winner'] === $entrant ? 1 : 0];

                if (! isset($last[$entrant]) || $key > $last[$entrant]) {
                    $last[$entrant] = $key;
                }
            }
        }

        uksort($last, fn (int $a, int $b): int => $last[$b] <=> $last[$a] ?: $a <=> $b);
        $groups = [[$champion]];
        $previous = null;

        foreach ($last as $entrant => $key) {
            if ($key === $previous) {
                $groups[count($groups) - 1][] = $entrant;
            } else {
                $groups[] = [$entrant];
            }

            $previous = $key;
        }

        return $groups;
    }

    /**
     * @param  list<BracketMatch>  $matches
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @param  array<string, MatchResult>  $results
     * @return list<list<int>>|null participant ids per place, best first
     */
    private function table(Tournament $tournament, array $matches, array $state, array $results): ?array
    {
        $format = $tournament->format === TournamentFormat::TwoStage ? TournamentFormat::RoundRobin : $tournament->format;

        if (! in_array($format, [TournamentFormat::Swiss, TournamentFormat::RoundRobin], true)) {
            return null;
        }

        $members = [];
        $games = [];

        foreach ($matches as $match) {
            $entrants = $state[$match->key]['entrants'] ?? [];

            foreach ($entrants as $entrant) {
                if ($entrant !== null) {
                    $members[$entrant] = true;
                }
            }

            if ($match->bracket === 'bye' && ($entrants[0] ?? null) !== null) {
                $games[] = [(int) $entrants[0], null, MatchResult::win(0)];
            } elseif (($state[$match->key]['status'] ?? null) === 'done' && count($entrants) === 2 && isset($results[$match->key])) {
                $games[] = [(int) $entrants[0], (int) $entrants[1], $results[$match->key]];
            }
        }

        // A tie nothing splits falls back to this order: by seed, as the public table has it (TournamentView::table()).
        // A disqualified entry places below everyone who stayed in, never on a prize place ahead of them.
        $rows = FirstPlaceTie::table($tournament, array_values(TournamentParticipant::query()->whereKey(array_keys($members))->orderBy('seed')->pluck('id')->map(intval(...))->all()), $games);
        // A tie for place 1 shared (FirstPlaceTie): one place for all who hold it, and its prize split.
        $shared = FirstPlaceTie::mode($tournament) !== FormatOptions::FIRST_PLACE_TIE_SEED ? FirstPlaceTie::leaders($rows, $tournament) : [];
        $groups = $shared === [] ? [] : [$shared];

        foreach ($rows as $row) {
            if (! in_array($row->entrant, $shared, true)) {
                $groups[] = [$row->entrant];
            }
        }

        return $groups;
    }

    /**
     * The places of a lobby tournament: the place number each entry has in
     * its lobby (shared places kept), the same number in every lobby shared;
     * null until every lobby is decided.
     *
     * @param  list<BracketMatch>  $lobbies
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @return list<array{place: int, participants: list<int>}>|null
     */
    private static function lobbies(Tournament $tournament, array $lobbies, array $state): ?array
    {
        $stored = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereIn('key', array_map(fn (BracketMatch $match): string => $match->key, $lobbies))
            ->get()->keyBy('key');
        $byPlace = [];

        foreach ($lobbies as $lobby) {
            $row = $state[$lobby->key] ?? null;
            $result = $stored->get($lobby->key)?->result;

            if (($row['status'] ?? null) !== 'done' || ! is_array($result)) {
                return null;
            }

            $unplaced = array_map(intval(...), (array) ($result['unplaced'] ?? []));
            $ranks = array_values(array_map(intval(...), (array) ($result['ranks'] ?? [])));

            foreach ($row['entrants'] as $slot => $entrant) {
                if ($entrant === null || in_array($entrant, $unplaced, true)) {
                    continue;
                }

                // A lobby without ranks (decided another way) ranks by its winner first, the others as they stand.
                $position = array_search($entrant, $row['ranking'], true);
                $place = $ranks[$slot] ?? (is_int($position) ? $position + 1 : count($row['entrants']));
                $byPlace[$place][] = $entrant;
            }
        }

        ksort($byPlace);

        return self::numbered(array_values(array_map(function (array $ids): array {
            sort($ids);

            return $ids;
        }, $byPlace)));
    }

    /**
     * Places from ordered groups of tied sides: 1, then 1 + the size of the groups before.
     *
     * @param  list<list<int>>  $groups
     * @return list<array{place: int, participants: list<int>}>
     */
    private static function numbered(array $groups): array
    {
        $places = [];
        $place = 1;

        foreach ($groups as $group) {
            $places[] = ['place' => $place, 'participants' => $group];
            $place += count($group);
        }

        return $places;
    }
}
