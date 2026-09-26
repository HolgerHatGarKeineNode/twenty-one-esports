<?php

namespace App\Support\Tournaments;

use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether a director has an interest in a match and so may not enter or
 * correct its result (security gate P8b). Director results count for Elo
 * (user decision), so a result must come from someone without a stake:
 *
 * - who plays in it (a member of either entry);
 * - who belongs to a clan in it: the clan of an entered lineup, or the clan
 *   of any player of either entry, as member, captain or owner, whether or
 *   not they were entered;
 * - who was named as director by someone with such an interest, along the
 *   whole chain of appointments. An organizer who plays taints every
 *   director he named, so an alt account cannot enter his win. A named
 *   director without a recorded appointer counts as named by the creator
 *   (fail closed).
 *
 * Admins are checked without the appointment chain: only their own stake
 * counts.
 */
final class TournamentInterest
{
    public static function of(Tournament $tournament, TournamentMatch $match, User $user, bool $followAppointers = true): bool
    {
        [$players, $clans] = self::stakes($match);

        if (self::holds($user->id, $players, $clans)) {
            return true;
        }

        if (! $followAppointers) {
            return false;
        }

        // Named director => who named them (none recorded: the creator). The creator and anyone
        // who is not a named director (an admin) end the chain.
        $appointers = DB::table('tournament_directors')->where('tournament_id', $tournament->id)
            ->get(['user_id', 'added_by_id'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => (int) ($row->added_by_id ?? $tournament->created_by_id)])
            ->all();

        $seen = [$user->id => true];
        $current = $appointers[$user->id] ?? null;

        while ($current !== null && ! isset($seen[$current])) {
            if (self::holds($current, $players, $clans)) {
                return true;
            }

            $seen[$current] = true;
            $current = $appointers[$current] ?? null;
        }

        return false;
    }

    /**
     * The players of both entries, and every clan in the match.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function stakes(TournamentMatch $match): array
    {
        $match->loadMissing('slots.participant');
        $players = [];
        $clans = [];

        foreach ($match->slots as $slot) {
            $participant = $slot->participant;

            if ($participant === null) {
                continue;
            }

            array_push($players, ...$participant->memberIds());

            if ($participant->lineup_id !== null) {
                $clans[] = (int) Lineup::query()->whereKey($participant->lineup_id)->value('clan_id');
            }
        }

        array_push($clans, ...ClanMember::query()->whereIn('user_id', $players)->pluck('clan_id')->map(intval(...))->all());

        return [array_values(array_unique($players)), array_values(array_unique(array_filter($clans)))];
    }

    /**
     * @param  list<int>  $players
     * @param  list<int>  $clans
     */
    private static function holds(int $userId, array $players, array $clans): bool
    {
        if (in_array($userId, $players, true)) {
            return true;
        }

        if ($clans === []) {
            return false;
        }

        return ClanMember::query()->where('user_id', $userId)->whereIn('clan_id', $clans)->exists()
            || Clan::query()->where('owner_id', $userId)->whereKey($clans)->exists();
    }
}
