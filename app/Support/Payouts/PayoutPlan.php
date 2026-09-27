<?php

namespace App\Support\Payouts;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;

/**
 * Who gets what from a tournament's pool (P9, open question 10 with the CEO
 * defaults): the pool is split by place in the tournament's percentages
 * (50/30/20 unless the organizer set another split before sign-up closed).
 *
 * - Tied places share: two sides tied for third share the percentages of
 *   places 3 and 4 equally (with 50/30/20 that is 10 % each).
 * - A team's share is split equally among its roster, the players the
 *   entry was drawn or registered with (`tournament_participants.members`),
 *   substitutes included.
 * - Every division rounds down to whole sats. What is left (remainders,
 *   places the split names but nobody holds, the shares of deleted
 *   accounts) stays in the wallet: it goes to the league reserve.
 *
 * Pure apart from reading the participants and their users.
 */
final class PayoutPlan
{
    public function __construct(private TournamentPlacements $placements) {}

    /**
     * @return array{rows: list<array{place: int, participant: TournamentParticipant, user: User, amount: int}>, remainder: int}|null null while the places cannot be read
     */
    public function compute(Tournament $tournament, int $poolSats): ?array
    {
        $places = $this->placements->of($tournament);

        if ($places === null) {
            return null;
        }

        $split = $tournament->prizeSplit();
        $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get()->keyBy('id');
        $rows = [];
        $paid = 0;

        foreach ($places as ['place' => $place, 'participants' => $ids]) {
            $percent = 0;

            for ($index = $place - 1; $index < $place - 1 + count($ids); $index++) {
                $percent += $split[$index] ?? 0;
            }

            if ($percent === 0 || $poolSats <= 0) {
                continue;
            }

            $perSide = intdiv(intdiv($poolSats * $percent, 100), count($ids));

            foreach ($ids as $id) {
                $participant = $participants->get($id);

                if (! $participant instanceof TournamentParticipant) {
                    continue;
                }

                $memberIds = $participant->memberIds();
                $perPlayer = $memberIds === [] ? 0 : intdiv($perSide, count($memberIds));

                if ($perPlayer <= 0) {
                    continue;
                }

                foreach (User::query()->whereIn('id', $memberIds)->orderBy('id')->get() as $user) {
                    $rows[] = ['place' => $place, 'participant' => $participant, 'user' => $user, 'amount' => $perPlayer];
                    $paid += $perPlayer;
                }
            }
        }

        return ['rows' => $rows, 'remainder' => max(0, $poolSats - $paid)];
    }
}
