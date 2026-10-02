<?php

namespace App\Support\Scores;

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\Tournaments\Engine\Slot;
use Illuminate\Support\Facades\DB;

/**
 * Entering a player into a leaderboard the league runs by itself, without a
 * sign-up (the weekly boards of Blockfill and TMNF: BlockfillWeeks,
 * App\Support\Tmnf\TmnfWeeks): a participant and a slot on the board's one
 * match, seeded in the order players came, once.
 */
final class LeaderboardEntries
{
    /**
     * Enters the player, once. True when the player was new to it.
     */
    public function join(Tournament $board, User $user): bool
    {
        return DB::transaction(function () use ($board, $user): bool {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($board->id);

            if (TournamentParticipant::query()->where(['tournament_id' => $locked->id, 'user_id' => $user->id])->exists()) {
                return false;
            }

            $seed = (int) TournamentParticipant::query()->where('tournament_id', $locked->id)->max('seed') + 1;
            $participant = TournamentParticipant::query()->create([
                'tournament_id' => $locked->id,
                'user_id' => $user->id,
                'name' => mb_substr($user->displayName(), 0, 80),
                'seed' => $seed,
                'members' => [$user->id],
            ]);

            $match = $this->match($locked);
            $match->slots()->create([
                'slot' => $match->slots()->count(),
                'source' => Slot::entrant($participant->id)->toArray(),
                'tournament_participant_id' => $participant->id,
            ]);

            // The size it is planned and shown with follows the entries (two at least, as a leaderboard needs).
            $locked->forceFill(['capacity' => max(2, $seed)])->save();

            return true;
        });
    }

    /**
     * The leaderboard's one board match (the engine's Leaderboard bracket:
     * stage 1, round 1, key `board`), made with the first entry.
     */
    private function match(Tournament $board): TournamentMatch
    {
        $match = TournamentMatch::query()->where(['tournament_id' => $board->id, 'bracket' => 'board'])->first();

        if ($match !== null) {
            return $match;
        }

        $stage = TournamentStage::query()->create(['tournament_id' => $board->id, 'number' => 1, 'format' => TournamentFormat::Leaderboard]);
        $round = TournamentRound::query()->create(['tournament_stage_id' => $stage->id, 'number' => 1]);

        return $board->matches()->create([
            'tournament_round_id' => $round->id,
            'key' => 'board',
            'group' => null,
            'bracket' => 'board',
            'position' => 1,
            'if_needed' => false,
            'status' => 'ready',
        ]);
    }
}
