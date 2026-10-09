<?php

namespace App\Support\Hyper;

use App\Enums\ClanRole;
use App\Enums\LineupRole;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hyperbitcoinization clan brackets (plan "Hyperbitcoinization", P5b): a knockout of clans playing 2v2 or 3v3, in
 * the tournament's mode (live or correspondence). The team size is the tournament's option (`teamSize`), never a
 * mode of the game.
 *
 * - **Entry**: a clan signs up through the tournament's lineup entry (TournamentSignups::enterLineup()), with the
 *   team and up to two substitutes. Its lineup is a mirror of the clan ({@see Lineup()}: every member seated, the
 *   owner and the clan's captains as captains), kept in the database only and never published: Hyperbitcoinization
 *   has no lineups of its own, its team is the clan (P4). Players enter no solo pool: there are no mix teams.
 * - **Lineup per match**: before each match an acting captain names who plays ({@see name()}). A clan that entered
 *   no more players than the team size needs no name; one that does not name within `tournament_lineup_minutes`
 *   (per mode) of the match being ready plays with the players it entered first. A player who left the clan since
 *   never plays for it; a team short of players is filled with bots of that team, and such a match is unrated.
 * - **Table**: one team match on the league's server (HyperMatches), seated A B A B (slot 0 is team 0), each team's
 *   clan in `team_clans`, rated on the season's terms. The team win is the bracket's winner
 *   (TournamentRunner::hyperMatchFinished()).
 * - **Forfeit**: as in every rated team match, a member who leaves or times out too often forfeits alone (a bot plays
 *   the seat on, the team plays on); a team whose every player forfeited loses the match.
 */
final class HyperTournamentTeams
{
    public function __construct(private TournamentMatchMaker $matchMaker) {}

    /** A Hyperbitcoinization tournament of clan teams (2v2, 3v3). */
    public static function isClanBracket(Tournament $tournament): bool
    {
        return $tournament->profile()->isHyper() && $tournament->teamSize() > 1;
    }

    /**
     * The lineup a clan enters a clan bracket with: every member seated (the owner and the captains as captains),
     * synced with the clan now. One per clan and mode; kept in the database only (no Nostr event).
     */
    public static function lineup(Clan $clan, string $mode): Lineup
    {
        return DB::transaction(function () use ($clan, $mode): Lineup {
            $lineup = Lineup::query()->firstOrCreate(['clan_id' => $clan->id, 'game' => Hyperbitcoinization::SLUG, 'mode' => $mode]);
            $members = ClanMember::query()->where('clan_id', $clan->id)->get(['user_id', 'role']);

            LineupSeat::query()->where('lineup_id', $lineup->id)->whereNotIn('user_id', $members->pluck('user_id'))->delete();

            foreach ($members as $member) {
                $role = $member->user_id === $clan->owner_id || $member->role === ClanRole::Captain ? LineupRole::Captain : LineupRole::Player;
                $seat = LineupSeat::query()->firstOrNew(['lineup_id' => $lineup->id, 'user_id' => $member->user_id]);
                $seat->fill(['role' => $role, 'accepted_at' => $seat->accepted_at ?? now()]);

                if ($seat->isDirty()) {
                    $seat->save();
                }
            }

            return $lineup->load(['clan', 'seats.user.clanMember']);
        });
    }

    /** How long a ready match waits for the clans to name their players, in minutes (per mode). */
    public static function waitMinutes(Tournament $tournament): int
    {
        return max(1, (int) config("esports.hyper.tournament_lineup_minutes.{$tournament->mode}", 10));
    }

    /**
     * When the league seats the players entered first, or null while the match has not waited yet.
     */
    public static function deadline(Tournament $tournament, TournamentMatch $match): ?CarbonImmutable
    {
        $since = $match->lineups['since'] ?? null;

        return $since === null ? null : CarbonImmutable::createFromTimestamp((int) $since)->addMinutes(self::waitMinutes($tournament));
    }

    /**
     * The entry's players who can play now, in the order it entered them: an account, and still in the clan.
     *
     * @return list<int>
     */
    public static function eligible(TournamentParticipant $participant): array
    {
        $ids = $participant->memberIds();
        $clanId = $participant->lineup_id === null ? null : Lineup::query()->whereKey($participant->lineup_id)->value('clan_id');
        $present = $clanId === null
            ? User::query()->whereKey($ids)->pluck('id')->all()
            : ClanMember::query()->where('clan_id', $clanId)->whereIn('user_id', $ids)->pluck('user_id')->all();
        $present = array_map(intval(...), $present);

        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $present, true)));
    }

    /**
     * Whether the entry in `$slot` of the match still has to name its players: it has more than the team size, and
     * no name of the match holds (a named player who left the clan since makes it name again).
     */
    public static function mustName(Tournament $tournament, TournamentMatch $match, TournamentParticipant $participant, int $slot): bool
    {
        $eligible = self::eligible($participant);

        return count($eligible) > $tournament->teamSize() && self::named($tournament, $match, $slot, $eligible) === null;
    }

    /**
     * The players named for `$slot`, when every one of them can still play; null otherwise.
     *
     * @param  list<int>  $eligible
     * @return list<int>|null
     */
    public static function named(Tournament $tournament, TournamentMatch $match, int $slot, array $eligible): ?array
    {
        $members = $match->lineups['sides'][$slot]['members'] ?? null;

        if (! is_array($members) || count($members) !== $tournament->teamSize()) {
            return null;
        }

        $members = array_map(intval(...), $members);

        return array_diff($members, $eligible) === [] ? $members : null;
    }

    /**
     * An acting captain names who plays the match for their clan. Starts the match at once when the other clan is
     * set too.
     *
     * @param  list<int>  $userIds
     *
     * @throws TournamentRuleViolation
     */
    public function name(Tournament $tournament, TournamentMatch $match, User $captain, array $userIds): void
    {
        DB::transaction(function () use ($tournament, $match, $captain, $userIds): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $match = TournamentMatch::query()->with(['slots.participant', 'hyperMatch'])->lockForUpdate()->findOrFail($match->id);

            if (! self::isClanBracket($locked) || $match->tournament_id !== $locked->id || $locked->status !== TournamentStatus::Running || $locked->isPaused()
                || $match->status !== 'ready' || $match->result !== null || ! TournamentMatchMaker::needsHyperMatch($match)) {
                throw new TournamentRuleViolation('lineup_closed', __('This match takes no lineup any more.'));
            }

            $slot = $match->slots->sortBy('slot')->first(function ($slot) use ($captain): bool {
                $lineup = $slot->participant?->lineup_id === null ? null : Lineup::query()->with(['clan', 'seats'])->find($slot->participant->lineup_id);

                return $lineup?->isActingCaptain($captain) ?? false;
            });

            if ($slot === null || $slot->participant === null) {
                throw new TournamentRuleViolation('not_captain', __('Only a captain of the clan can name its players.'));
            }

            $ids = array_values(array_unique(array_map(intval(...), $userIds)));
            $size = $locked->teamSize();

            if (count($ids) !== $size) {
                throw new TournamentRuleViolation('lineup_size', __('Pick exactly :count players.', ['count' => $size]));
            }

            if (array_diff($ids, self::eligible($slot->participant)) !== []) {
                throw new TournamentRuleViolation('member_foreign', __('Only players your clan entered and who are still in it can play.'));
            }

            $lineups = $match->lineups ?? [];
            $lineups['since'] ??= now()->getTimestamp();
            $lineups['sides'][$slot->slot] = ['members' => $ids, 'by' => $captain->id, 'at' => now()->getTimestamp()];
            $match->forceFill(['lineups' => $lineups])->save();
        });

        $this->matchMaker->startReady($tournament->refresh());
    }

    /**
     * The seats and clans of the match's team table (inside the start's lock), or null while a clan still has time
     * to name its players. The first call marks the match as waiting (`lineups.since`).
     *
     * @return array{seats: list<array{user?: User, bot?: bool, team: int}>, clans: list<int|null>}|null
     */
    public static function seating(Tournament $tournament, TournamentMatch $match): ?array
    {
        $size = $tournament->teamSize();
        $slots = $match->slots->sortBy('slot')->values();
        $lineups = $match->lineups ?? [];

        if (! isset($lineups['since'])) {
            $lineups['since'] = now()->getTimestamp();
            $match->forceFill(['lineups' => $lineups])->save();
        }

        $due = self::deadline($tournament, $match)?->isPast() ?? false;
        $teams = [];
        $clans = [];

        foreach ($slots as $team => $slot) {
            $participant = $slot->participant;

            if ($participant === null) {
                return null;
            }

            $eligible = self::eligible($participant);
            $players = self::named($tournament, $match, (int) $slot->slot, $eligible);

            if ($players === null && count($eligible) > $size && ! $due) {
                return null;
            }

            $teams[$team] = $players ?? array_slice($eligible, 0, $size);
            $clans[] = $participant->lineup_id === null ? null : (int) Lineup::query()->whereKey($participant->lineup_id)->value('clan_id');
        }

        $users = User::query()->whereKey(array_merge(...$teams))->get()->keyBy('id');
        $seats = [];

        // A B A B (A B A B A B): each team's players in turn, a missing player a bot of that team.
        for ($index = 0; $index < $size; $index++) {
            foreach ([0, 1] as $team) {
                $user = $users->get($teams[$team][$index] ?? 0);
                $seats[] = $user instanceof User ? ['user' => $user, 'team' => $team] : ['bot' => true, 'team' => $team];
            }
        }

        return ['seats' => $seats, 'clans' => $clans];
    }
}
