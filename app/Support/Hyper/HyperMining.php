<?php

namespace App\Support\Hyper;

use App\Models\ClanMember;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Hyperbitcoinization in the season chain (NIP rev. 9.26, draft): which
 * match can mine, and what it pins when it starts.
 *
 * Dark by default: the game mines only while the live season's parameters
 * carry a weight for `hyperbitcoinization/<mode>` ({@see weight()}). Without
 * one a match pins nothing and its result is signed as before, by
 * HyperPublisher.
 *
 * One block per match, for one player: the winner of a rated free-for-all
 * or 1v1 between players only. A team match and a tournament match never
 * mine. The winner mines only if they would have mined against every other
 * seat one by one (Candidate::$table, ConsensusRules), so the match pins
 * every seat, not two gatekeepers:
 *
 * - every seat's trust rank, assertion and anchor (a seat barred under the
 *   fair-play rules is left out, as RatedTrustGate::pin() does, and so
 *   counts as not trusted),
 * - every pair of seats that list each other as opponents, with the two
 *   list events,
 * - every seat's clan.
 *
 * The pin never refuses a match: rated play is as open as before, a table
 * that fails a rule plays for points or Elo and mines nothing.
 *
 * @phpstan-type Pin array{trust_key: string, minimum: int, players: array<string, array{rank: int, assertion: ?string, anchor: array{0: string, 1: int}|null}>, pairs: list<array{0: string, 1: string, 2: string, 3: string}>, clans: array<string, string>}
 */
final class HyperMining
{
    public const GAME = 'hyperbitcoinization';

    /** The weight in force for a mode in the live season at `$at`, 0 when the game does not mine (or outside a season). */
    public static function weight(string $mode, ?CarbonImmutable $at = null): int
    {
        $live = Seasons::live();

        return $live === null ? 0 : $live->chainParameters()->inForceAt($at ?? CarbonImmutable::now())->weightFor(self::GAME.'/'.$mode);
    }

    /** A rated free-for-all or 1v1 of the live season outside a tournament: the only matches that can mine. */
    public static function eligible(HyperMatch $match): bool
    {
        return $match->rated && $match->season !== null && $match->season === Seasons::live()?->slug
            && $match->tournament_match_id === null && ! $match->isTeamMatch();
    }

    /**
     * What a match that starts now pins for the chain, or null: the game has
     * no weight, the trust ranks are not computed, or the pin failed (it is
     * reported; the match starts all the same and never mines).
     *
     * @param  list<User>  $players  every seat's player
     * @return Pin|null
     */
    public static function pinAtStart(array $players, string $mode): ?array
    {
        try {
            if (self::weight($mode) === 0) {
                return null;
            }

            $gate = app(RatedTrustGate::class);

            if (! $gate->isAvailable() || count($players) < 2) {
                return null;
            }

            $pubkeys = array_map(fn (User $player): string => $player->pubkey, $players);
            $pin = ['trust_key' => '', 'minimum' => RatedTrustGate::minimum(), 'players' => [], 'pairs' => [], 'clans' => []];

            // Every pair of seats, as a 1v1 between the two would pin it: ranks, and whether they list each other.
            foreach ($pubkeys as $index => $first) {
                foreach (array_slice($pubkeys, $index + 1) as $second) {
                    $pair = $gate->pin([$first, $second], [$first, $second]);
                    $pin['trust_key'] = $pin['trust_key'] === '' ? $pair->trustKey : $pin['trust_key'];

                    foreach ($pair->players as $pubkey => $facts) {
                        $pin['players'][$pubkey] = ['rank' => (int) $facts['rank'], 'assertion' => $facts['assertion'] ?? null, 'anchor' => $facts['anchor'] ?? null];
                    }

                    if ($pair->connected) {
                        $pin['pairs'][] = [$first, $second, (string) ($pair->players[$first]['list'] ?? ''), (string) ($pair->players[$second]['list'] ?? '')];
                    }
                }
            }

            foreach (ClanMember::query()->with(['clan', 'user'])->whereIn('user_id', array_map(fn (User $player): int => $player->id, $players))->get() as $member) {
                $pin['clans'][$member->user->pubkey] = $member->clan->address();
            }

            return $pin;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The one seat that won: the seat the rules named the winner, in place
     * 1 alone, a player who did not forfeit. Null when nobody did: every
     * seat forfeited, place 1 is shared, or the bot that played a forfeited
     * seat on won (the best seat that stayed then holds place 1, but beat
     * nobody).
     */
    public static function winner(HyperMatch $match): ?HyperSeat
    {
        $first = $match->seats->filter(fn (HyperSeat $seat): bool => $seat->place === 1)->values();
        $seat = $first->count() === 1 ? $first[0] : null;

        return $seat === null || $seat->seat !== $match->winner_seat || $seat->user === null || HyperSeason::forfeited($seat) ? null : $seat;
    }

    /**
     * The win is a forfeit for the chain (consensus rule 2) when no other
     * seat played to the end: every opponent left or let their turns run
     * out, so the winner beat nobody. A seat that forfeits is placed last
     * and never wins; one that stays is a loser like any other.
     */
    public static function wonByForfeit(HyperMatch $match, HyperSeat $winner): bool
    {
        return ! $match->seats->contains(fn (HyperSeat $seat): bool => $seat->seat !== $winner->seat && ! HyperSeason::forfeited($seat));
    }
}
