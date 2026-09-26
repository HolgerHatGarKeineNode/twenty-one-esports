<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Standings;

/**
 * Who won a finished tournament (P11, the "Tournament win" share card), read
 * from the stored bracket and results with the engine, never stored itself:
 *
 * - a final stage that ends in one match (an elimination final, the reset of
 *   a double elimination, a final heat): its first place; a reset that was not
 *   needed hands over to the grand final it hangs on;
 * - a final stage that is a table (round robin, Swiss): place 1 of the table,
 *   with the stage's points and tie-breaks as the tournament page ranks it.
 *
 * Null while the tournament is not finished or when no single winner can be
 * read (a stage of parallel heats, a table tie the tie-breaks cannot split).
 */
final class TournamentChampion
{
    public function __construct(private TournamentBrackets $brackets) {}

    public function of(Tournament $tournament): ?TournamentParticipant
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        $bracket = $this->brackets->load($tournament);
        $results = $this->brackets->results($tournament);
        $options = $tournament->formatOptions();
        $state = Advancement::resolve($bracket, $results, $options);

        if ($bracket->matches === []) {
            return null;
        }

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

        if (count($terminal) === 1) {
            $last = $state[$terminal[0]->key] ?? null;

            if (($last['status'] ?? null) === 'skipped') {
                $last = $state[(string) $terminal[0]->slots[0]->match] ?? null;
            }

            $winner = ($last['status'] ?? null) === 'done' ? ($last['ranking'][0] ?? $last['winner'] ?? null) : null;

            return $winner === null ? null : TournamentParticipant::query()->whereKey($winner)->where('tournament_id', $tournament->id)->first();
        }

        return $this->tableLeader($tournament, $final, $state, $results);
    }

    /**
     * @param  list<BracketMatch>  $matches
     * @param  array<string, array{entrants: list<int|null>, status: string, winner: int|null, loser: int|null, ranking: list<int>}>  $state
     * @param  array<string, MatchResult>  $results
     */
    private function tableLeader(Tournament $tournament, array $matches, array $state, array $results): ?TournamentParticipant
    {
        $format = $tournament->format === TournamentFormat::TwoStage ? TournamentFormat::RoundRobin : $tournament->format;

        if (! in_array($format, [TournamentFormat::Swiss, TournamentFormat::RoundRobin], true)) {
            return null;
        }

        $options = $tournament->formatOptions();
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

        $swiss = $format === TournamentFormat::Swiss;
        $custom = $options->rankBy === 'custom';
        $rows = Standings::table(
            array_map(intval(...), array_keys($members)),
            $games,
            $swiss || $custom ? $options->pointsWin : 1.0,
            $swiss || $custom ? $options->pointsTie : 0.5,
            $swiss ? $options->pointsBye : 0.0,
            $swiss ? 'points' : $options->rankBy,
            $swiss ? $options->swissTieBreaks : $options->roundRobinTieBreaks,
        );

        $leaders = array_values(array_filter($rows, fn ($row): bool => $row->rank === 1));

        return count($leaders) !== 1 ? null
            : TournamentParticipant::query()->whereKey($leaders[0]->entrant)->where('tournament_id', $tournament->id)->first();
    }
}
