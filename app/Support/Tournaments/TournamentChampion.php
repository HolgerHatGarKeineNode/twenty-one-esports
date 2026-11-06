<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\MatchResult;

/**
 * Who won a finished tournament (P11, the "Tournament win" share card), read
 * from the stored bracket and results with the engine, never stored itself:
 *
 * - a final stage that ends in one match (an elimination final, the reset of
 *   a double elimination, a final heat): its first place; a reset that was not
 *   needed hands over to the grand final it hangs on;
 * - a final stage that is a table (round robin, Swiss): place 1 of the table,
 *   with the stage's points and tie-breaks as the tournament page ranks it;
 *   a tie for place 1 nothing splits is played off in a knockout (stage 2,
 *   FirstPlaceTie, read as a final stage that ends in one match) or shared
 *   (no single champion), as the organizer chose, or goes to the seeds where
 *   the options predate that choice.
 *
 * Null while the tournament is not finished or when no single winner can be
 * read (a stage of parallel heats, a lobby whose place 1 is shared, a table
 * tie the tie-breaks cannot split).
 */
final class TournamentChampion
{
    public function __construct(private TournamentBrackets $brackets) {}

    public function of(Tournament $tournament): ?TournamentParticipant
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        // A lobby tournament (P10): its places across all lobbies; a shared or empty place 1 is no single champion.
        if (Lobbies::isLobby($tournament)) {
            $first = app(TournamentPlacements::class)->of($tournament)[0] ?? null;

            return $first !== null && $first['place'] === 1 && count($first['participants']) === 1
                ? TournamentParticipant::query()->whereKey($first['participants'][0])->where('tournament_id', $tournament->id)->first()
                : null;
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

            // A shared place 1 (the allies of a lobby, P10) is no single champion.
            $ranks = $results[$terminal[0]->key]->ranks ?? null;

            if ($ranks !== null && $ranks !== [] && count(array_keys($ranks, min($ranks), true)) > 1) {
                return null;
            }

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

        // A tie nothing splits falls back to this order: by seed, as the public table has it (TournamentView::table()); a
        // disqualified entry below everyone who stayed in, as the payout places have it.
        $rows = FirstPlaceTie::table($tournament, array_values(TournamentParticipant::query()->whereKey(array_keys($members))->orderBy('seed')->pluck('id')->map(intval(...))->all()), $games);

        // A tie for place 1 the organizer did not leave to the seeds (FirstPlaceTie): shared, so no single champion.
        // One that is played off ends in the knockout of stage 2, read above.
        if (FirstPlaceTie::mode($tournament) !== FormatOptions::FIRST_PLACE_TIE_SEED && FirstPlaceTie::leaders($rows, $tournament) !== []) {
            return null;
        }

        $leader = $rows[0] ?? null;

        return $leader === null ? null
            : TournamentParticipant::query()->whereKey($leader->entrant)->where('tournament_id', $tournament->id)->first();
    }
}
