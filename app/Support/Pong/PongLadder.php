<?php

namespace App\Support\Pong;

use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Models\PongRating;
use App\Models\User;
use App\Support\Rating\EloRating;

/**
 * Proof of Pong's Elo ladder on the league's surfaces (plan "Proof of Pong", P4): the standings of the players'
 * permanent Elo (PongRatings, its own table as Hyperbitcoinization's), read by the ladder page, the ladder grid on
 * home, the page cards and the stream, and a player's card on the player page. Only players with a rated result
 * stand on it; the order is Elo, then results, then who got there first.
 */
final class PongLadder
{
    /** Finished matches a player card reads to find the figure the player picks most. */
    private const FIGURE_SAMPLE = 30;

    /**
     * The first `$limit` players.
     *
     * @return list<array{rank: int, user: User, rating: int, results: int, wins: int, losses: int, provisional: bool}>
     */
    public static function standings(int $limit = 100): array
    {
        $provisional = EloRating::fromConfig('casual')->provisional;

        return array_values(PongRating::query()->where('results', '>', 0)->whereHas('user')->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit($limit)->get()
            ->values()->map(fn (PongRating $row, int $index): array => [
                'rank' => $index + 1,
                'user' => $row->user,
                'rating' => $row->rating,
                'results' => $row->results,
                'wins' => $row->wins,
                'losses' => $row->losses,
                'provisional' => $row->results < $provisional,
            ])->all());
    }

    /** How many players stand on the ladder. */
    public static function entries(): int
    {
        return PongRating::query()->where('results', '>', 0)->count();
    }

    /**
     * A player's place on the ladder, or null without a rated result.
     */
    public static function rankOf(User $user): ?int
    {
        $row = PongRating::query()->where('user_id', $user->id)->where('results', '>', 0)->first();

        if ($row === null) {
            return null;
        }

        return 1 + PongRating::query()->where('results', '>', 0)->where(fn ($query) => $query
            ->where('rating', '>', $row->rating)
            ->orWhere(fn ($query) => $query->where('rating', $row->rating)->where('results', '>', $row->results))
            ->orWhere(fn ($query) => $query->where('rating', $row->rating)->where('results', $row->results)->where('id', '<', $row->id)))->count();
    }

    /**
     * The player page's card: Elo (the start value until a rated match), wins and losses of the finished live
     * matches, the place on the ladder and the figure the player picked most in their latest matches (null: none
     * picked). Null for a player who never finished a live match.
     *
     * @return array{rating: int, provisional: bool, rated: bool, rank: int|null, matches: int, wins: int, losses: int, figure: array{id: string, name: string}|null}|null
     */
    public static function card(User $user): ?array
    {
        $finished = PongMatch::query()->playedBy($user)->where('status', PongMatchStatus::Finished);
        $row = (clone $finished)->toBase()
            ->selectRaw('count(*) as matches, sum(case when winner_id = ? then 1 else 0 end) as wins', [$user->id])->first();
        $matches = (int) ($row->matches ?? 0);

        if ($matches === 0) {
            return null;
        }

        $rating = PongRatings::of($user->id);
        $wins = (int) $row->wins;

        return [
            'rating' => $rating['rating'],
            'provisional' => $rating['provisional'],
            'rated' => $rating['results'] > 0,
            'rank' => self::rankOf($user),
            'matches' => $matches,
            'wins' => $wins,
            'losses' => $matches - $wins,
            'figure' => self::favouriteFigure($user, array_values($finished->latest('id')->limit(self::FIGURE_SAMPLE)->get(['id', 'left_id', 'right_id', 'state'])->all())),
        ];
    }

    /**
     * The figure picked most in these matches, the newest pick on a tie; null when none was picked.
     *
     * @param  list<PongMatch>  $matches  newest first
     * @return array{id: string, name: string}|null
     */
    private static function favouriteFigure(User $user, array $matches): ?array
    {
        $counts = [];

        foreach ($matches as $match) {
            $side = $match->sideOf($user);
            $figure = $side === null ? null : PongMatches::figuresOf($match)[$side];

            if (PongCast::isPlayer($figure)) {
                // The first seen is the newest: a later equal count never takes its place.
                $counts[$figure] = ($counts[$figure] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $best = array_keys($counts, max($counts), true)[0];
        $names = array_column(PongCast::players(), 'name', 'id');

        return ['id' => $best, 'name' => $names[$best] ?? $best];
    }
}
