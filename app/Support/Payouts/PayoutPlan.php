<?php

namespace App\Support\Payouts;

use App\Enums\SeriesResolution;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\Prizes\PrizePool;

/**
 * Who gets what from a tournament's pot (P9, open question 10 with the CEO
 * defaults): by place, either in the tournament's percentages of `$poolSats`
 * (50/30/20 unless the organizer set another split before sign-up closed)
 * or its fixed amounts (user, 2026-09-27), each with its share of the zaps
 * on top ({@see PrizePool::zapBonus()}, user 2026-10-02).
 *
 * - Tied places share: two sides tied for third share the percentages of
 *   places 3 and 4 equally (with 50/30/20 that is 10 % each), or the sum of
 *   their fixed amounts.
 * - A team's share is split equally among its roster, the players the
 *   entry was drawn or registered with (`tournament_participants.members`),
 *   substitutes included.
 * - Every division rounds down to whole sats. What is left (remainders,
 *   places the split names but nobody holds, the shares of deleted
 *   accounts) stays in the pot's wallet.
 * - A second account linked to the main account of the same person (P41,
 *   {@see AccountLinks}) wins nothing: its share is not written and stays in
 *   the pot, never moved to a teammate or the next place.
 *
 * - Who did not play wins nothing (user, 2026-10-04: "verhindern, dass Leute die no-show komplett sind oder
 *   disqualified irgendwelche Auszahlungen bekommen … Der Pot muss sich dann auf die verteilen, die wirklich
 *   mitgespielt haben"): a disqualified entry, and an entry that lost a match as a no-show ({@see excluded()}), is
 *   taken out of the places, and everyone behind it moves up, so their share goes to those who played.
 *
 * Pure apart from reading the participants, the matches and the users.
 */
final class PayoutPlan
{
    public function __construct(private TournamentPlacements $placements) {}

    /**
     * @return array{rows: list<array{place: int, participant: TournamentParticipant, user: User, amount: int}>, remainder: int}|null null while the places cannot be read
     */
    public function compute(Tournament $tournament, int $poolSats, int $zapSats = 0): ?array
    {
        $places = $this->placements->of($tournament);

        if ($places === null) {
            return null;
        }

        $places = self::withoutExcluded($places, self::excluded($tournament));

        $split = $tournament->prizeSplit();
        $fixed = $tournament->prizeMode() === Tournament::PRIZES_FIXED ? $tournament->prizeFixed() : null;
        // Zaps on top of fixed prizes are split like them (percent prizes have them in `$poolSats`).
        $bonus = $fixed === null ? [] : PrizePool::zapBonus($fixed, $zapSats);
        $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get()->keyBy('id');
        $rows = [];
        $paid = 0;

        foreach ($places as ['place' => $place, 'participants' => $ids]) {
            $percent = 0;
            $amount = 0;

            for ($index = $place - 1; $index < $place - 1 + count($ids); $index++) {
                $percent += $split[$index] ?? 0;
                $amount += ($fixed[$index] ?? 0) + ($bonus[$index] ?? 0);
            }

            $group = $fixed === null ? intdiv(max(0, $poolSats) * $percent, 100) : $amount;

            if ($group <= 0) {
                continue;
            }

            $perSide = intdiv($group, count($ids));

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
                    if (FairPlay::isLinked($user->pubkey)) {
                        continue;
                    }

                    $rows[] = ['place' => $place, 'participant' => $participant, 'user' => $user, 'amount' => $perPlayer];
                    $paid += $perPlayer;
                }
            }
        }

        return ['rows' => $rows, 'remainder' => max(0, $poolSats - $paid)];
    }

    /**
     * The entries that win nothing: disqualified ones, both sides of a match decided as a double no-show, the side
     * that lost by a no-show (reported and unanswered, or not checked in) or as withdrawn.
     *
     * @return array<int, true> participant id => true
     */
    public static function excluded(Tournament $tournament): array
    {
        $excluded = array_fill_keys(TournamentParticipant::query()->where('tournament_id', $tournament->id)->whereNotNull('disqualified_at')->pluck('id')->all(), true);
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotNull('result')->with(['slots', 'seriesMatch'])->get();

        foreach ($matches as $match) {
            $result = (array) $match->result;
            $sides = $match->slots->pluck('tournament_participant_id')->all();

            if (($result['decided'] ?? null) === 'noshow') {
                foreach (array_filter($sides) as $id) {
                    $excluded[(int) $id] = true;
                }

                continue;
            }

            $winner = $result['winner'] ?? null;

            if (! is_int($winner) || count($sides) !== 2) {
                continue;
            }

            $series = $match->seriesMatch;
            $noShowLoss = ($result['decided'] ?? null) === 'withdrawn'
                || ($series !== null && $series->resolution === SeriesResolution::Forfeit && $series->noshow_reported_at !== null);
            $loser = $sides[1 - $winner] ?? null;

            if ($noShowLoss && $loser !== null) {
                $excluded[(int) $loser] = true;
            }
        }

        return $excluded;
    }

    /**
     * The places without the excluded entries, numbered again so everyone behind moves up (ties stay together).
     *
     * @param  list<array{place: int, participants: list<int>}>  $places
     * @param  array<int, true>  $excluded
     * @return list<array{place: int, participants: list<int>}>
     */
    private static function withoutExcluded(array $places, array $excluded): array
    {
        $kept = [];
        $next = 1;

        foreach ($places as ['participants' => $ids]) {
            $ids = array_values(array_filter($ids, fn (int $id): bool => ! isset($excluded[$id])));

            if ($ids !== []) {
                $kept[] = ['place' => $next, 'participants' => $ids];
                $next += count($ids);
            }
        }

        return $kept;
    }
}
