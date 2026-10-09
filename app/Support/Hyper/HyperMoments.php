<?php

namespace App\Support\Hyper;

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Hyperbitcoinization moments a player may share (plan "Hyperbitcoinization", P6, App\Support\Cards\SharePosts
 * type `hyper`): a finished match of theirs that their clan won (`team`), that they won (`win`), or in which they
 * collected sats as loot (`sats`; sats are game points, nothing is paid). A voided match, a running one and one
 * without a win or loot are no moment.
 *
 * @phpstan-type Moment array{kind: 'team'|'win'|'sats', sats: float, team: string|null, size: string, opponents: int}
 */
final class HyperMoments
{
    /** How many moments the start page lists. */
    public const LATEST = 5;

    /**
     * The player's moment in a match, or null.
     *
     * @return Moment|null
     */
    public static function of(HyperMatch $match, User $user): ?array
    {
        if ($match->status !== HyperMatchStatus::Finished) {
            return null;
        }

        $match->loadMissing('seats.user');
        $seat = $match->seats->first(fn (HyperSeat $seat): bool => (int) $seat->user_id === (int) $user->id);

        if ($seat === null) {
            return null;
        }

        $team = $match->isTeamMatch();
        $winner = $match->seats->firstWhere('place', 1);
        $teamWon = $team && $winner !== null && $winner->team === $seat->team;
        $sats = round((float) $seat->loot, 1);
        $kind = match (true) {
            $teamWon => 'team',
            ! $team && $seat->place === 1 => 'win',
            $sats > 0 => 'sats',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $seats = $match->seats->count();

        return [
            'kind' => $kind,
            'sats' => $sats,
            'team' => $teamWon ? (HyperTeams::sides($match->team_clans)[(int) $seat->team]['name'] ?? null) : null,
            'size' => $team ? intdiv($seats, 2).'v'.intdiv($seats, 2) : (string) $seats,
            'opponents' => $seats - 1,
        ];
    }

    /**
     * The player's latest finished matches that are a moment, newest first.
     *
     * @return list<array{match: HyperMatch, moment: Moment}>
     */
    public static function latest(User $user, int $limit = self::LATEST): array
    {
        $matches = HyperMatch::query()->where('status', HyperMatchStatus::Finished)
            ->whereHas('seats', fn (Builder $seats): Builder => $seats->where('user_id', $user->id)->where(fn (Builder $seat): Builder => $seat->where('place', 1)->orWhere('loot', '>', 0)->orWhereNotNull('team')))
            ->with('seats.user')->latest('ended_at')->limit($limit * 2)->get();
        $moments = [];

        foreach ($matches as $match) {
            $moment = self::of($match, $user);

            if ($moment !== null && count($moments) < $limit) {
                $moments[] = ['match' => $match, 'moment' => $moment];
            }
        }

        return $moments;
    }
}
