<?php

namespace App\Support\Pong;

use App\Models\PongMatch;
use App\Models\PongRating;
use App\Support\FairPlay\FairPlay;
use App\Support\Rating\EloRating;

/**
 * The players' Proof of Pong Elo (plan "Proof of Pong", P2), in its own table as Hyperbitcoinization's: one permanent
 * rating per player (no season, no tier, nothing that mines), with the league's casual Elo values
 * (EloRating::fromConfig('casual'), `season.casual`) and the conventions of the board games' ratings
 * (App\Support\Board\RatedBoard, App\Support\Rating\RatingService):
 *
 * - offered(): the switch `esports.pong.rated`; a match started while it is off stays unrated;
 * - only a live match between two players is rated (`pong_matches.rated`), never a game against a bot (those are not
 *   stored at all);
 * - two accounts of one person rate nothing (FairPlay::samePerson), and at most `season.casual.daily_pair_limit`
 *   matches of the same two players per UTC day move their ratings (the farming guard);
 * - side 0 is the challenger in EloRating::rate(), each side with its own k-factor.
 *
 * The change is written onto the match (`*_rating_before/after`), in the transaction that finishes it, once: a match
 * that has its ratings is never rated again.
 */
final class PongRatings
{
    /** Whether live matches are rated at all (`esports.pong.rated`). */
    public static function offered(): bool
    {
        return (bool) config('esports.pong.rated', true);
    }

    /**
     * Rate a finished match: winner 1, loser 0.
     *
     * @return bool whether the ratings moved
     */
    public function apply(PongMatch $match): bool
    {
        if (! $match->rated || $match->winner_id === null || $match->left_id === null || $match->right_id === null || $match->left_rating_after !== null) {
            return false;
        }

        if (FairPlay::samePerson([$match->left_id], [$match->right_id]) || $this->pairCapReached($match)) {
            return false;
        }

        $engine = EloRating::fromConfig('casual');

        foreach ([$match->left_id, $match->right_id] as $userId) {
            PongRating::query()->insertOrIgnore([[
                'user_id' => $userId, 'rating' => $engine->start, 'results' => 0, 'wins' => 0, 'losses' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]]);
        }

        // Lock in id order, so two matches of the same players cannot deadlock.
        $ratings = PongRating::query()->whereIn('user_id', [$match->left_id, $match->right_id])->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');
        $left = $ratings[$match->left_id];
        $right = $ratings[$match->right_id];
        $score = $match->winner_id === $match->left_id ? 1.0 : 0.0;
        $rated = $engine->rate($left->rating, $right->rating, $score, $left->results, $right->results);

        $match->forceFill([
            'left_rating_before' => $left->rating,
            'left_rating_after' => $rated['challenger'],
            'right_rating_before' => $right->rating,
            'right_rating_after' => $rated['challenged'],
        ])->save();

        $this->count($left, $rated['challenger'], $score === 1.0);
        $this->count($right, $rated['challenged'], $score === 0.0);

        return true;
    }

    /**
     * A player's rating: the start value without a rated match yet.
     *
     * @return array{rating: int, results: int, wins: int, losses: int, provisional: bool}
     */
    public static function of(?int $userId): array
    {
        $engine = EloRating::fromConfig('casual');
        $row = $userId === null ? null : PongRating::query()->where('user_id', $userId)->first();
        $results = $row->results ?? 0;

        return [
            'rating' => $row->rating ?? $engine->start,
            'results' => $results,
            'wins' => $row->wins ?? 0,
            'losses' => $row->losses ?? 0,
            'provisional' => $results < $engine->provisional,
        ];
    }

    private function count(PongRating $rating, int $value, bool $won): void
    {
        $rating->forceFill([
            'rating' => $value,
            'results' => $rating->results + 1,
            'wins' => $rating->wins + ($won ? 1 : 0),
            'losses' => $rating->losses + ($won ? 0 : 1),
        ])->save();
    }

    /** The same two players already moved their ratings `daily_pair_limit` times today (UTC). */
    private function pairCapReached(PongMatch $match): bool
    {
        $limit = config('season.casual.daily_pair_limit');

        if ($limit === null) {
            return false;
        }

        $today = PongMatch::query()
            ->whereKeyNot($match->id)
            ->whereNotNull('left_rating_after')
            ->where('ended_at', '>=', now()->utc()->startOfDay())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('left_id', $match->left_id)->where('right_id', $match->right_id))
                ->orWhere(fn ($query) => $query->where('left_id', $match->right_id)->where('right_id', $match->left_id)))
            ->count();

        return $today >= (int) $limit;
    }
}
