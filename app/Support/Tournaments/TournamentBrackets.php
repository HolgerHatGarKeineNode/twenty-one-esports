<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\Bracket;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\Entrant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stores the bracket of a tournament: seeds and groups on the participants,
 * then stages, rounds, matches and slots, each match marked ready or waiting.
 * Runs once, when sign-up closes (P8b draws the seed from a Bitcoin block
 * hash; the same participants and seed always give the same bracket).
 */
final class TournamentBrackets
{
    public function generate(Tournament $tournament, string $seed): Bracket
    {
        if ($tournament->matches()->exists()) {
            throw new RuntimeException("Tournament {$tournament->id} already has its bracket.");
        }

        $participants = $tournament->participants()->orderBy('id')->get();

        if ($participants->count() < 2) {
            throw new RuntimeException("Tournament {$tournament->id} needs at least 2 participants.");
        }

        $options = $tournament->formatOptions();
        $entrants = array_values($participants->map(fn (TournamentParticipant $participant): Entrant => new Entrant($participant->id, $participant->rating))->all());
        $bracket = BracketBuilder::build($tournament->format, $entrants, $options, $seed);
        $state = Advancement::resolve($bracket, [], $options);

        DB::transaction(function () use ($tournament, $bracket, $state, $seed, $options): void {
            $tournament->forceFill(['seed' => $seed])->save();
            $groupOf = [];

            foreach ($bracket->groups as $number => $members) {
                foreach ($members as $member) {
                    $groupOf[$member] = $number;
                }
            }

            foreach ($bracket->seeds as $index => $participantId) {
                TournamentParticipant::query()->whereKey($participantId)->update(['seed' => $index + 1, 'group' => $groupOf[$participantId] ?? null]);
            }

            /** @var array<string, TournamentRound> $rounds */
            $rounds = [];

            foreach ($bracket->rounds() as $number => $count) {
                $stage = TournamentStage::query()->create([
                    'tournament_id' => $tournament->id,
                    'number' => $number,
                    'format' => $this->stageFormat($tournament->format, $number, $options),
                ]);

                for ($round = 1; $round <= $count; $round++) {
                    $rounds["{$number}-{$round}"] = TournamentRound::query()->create(['tournament_stage_id' => $stage->id, 'number' => $round]);
                }
            }

            foreach ($bracket->matches as $match) {
                $row = $tournament->matches()->create([
                    'tournament_round_id' => $rounds["{$match->stage}-{$match->round}"]->id,
                    'key' => $match->key,
                    'group' => $match->group,
                    'bracket' => $match->bracket,
                    'position' => $match->position,
                    'if_needed' => $match->ifNeeded,
                    'status' => $match->bracket === 'bye' ? 'done' : $state[$match->key]['status'],
                ]);

                foreach ($match->slots as $index => $slot) {
                    $row->slots()->create([
                        'slot' => $index,
                        'source' => $slot->toArray(),
                        'tournament_participant_id' => $state[$match->key]['entrants'][$index] ?? null,
                    ]);
                }
            }
        });

        return $bracket;
    }

    private function stageFormat(TournamentFormat $format, int $stage, FormatOptions $options): TournamentFormat
    {
        if ($format !== TournamentFormat::TwoStage) {
            return $format;
        }

        return TournamentFormat::from($stage === 1 ? $options->groupStage : $options->finalStage);
    }
}
