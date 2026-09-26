<?php

namespace App\Support\Engagement;

use App\Models\PlacementReveal;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\User;
use App\Support\Rating\RankTiers;

/**
 * Placement (P10): with the fifth rated result in a ladder
 * (`season.rating.provisional`) a player leaves "provisional" and gets a
 * tier. That moment is revealed once, with the rank the result placed them
 * in.
 *
 * - {@see record()} writes the reveal when a rated change is the placing one;
 *   the unique (player, rating) makes a retry a no-op. A lineup's placement
 *   is revealed to the players who played it.
 * - {@see claim()} hands out an unshown reveal and marks it shown in one
 *   conditional UPDATE, so two page loads at once cannot both show it.
 */
final class Placements
{
    /**
     * @param  list<int>  $players  who played this side of the result
     */
    public function record(RatingChange $change, array $players): int
    {
        $rating = $change->rating;
        $tiers = RankTiers::fromConfig();
        $placed = $change->results_before + 1;

        if ($rating->pool !== Rating::RATED || $placed !== $tiers->provisional || $players === []) {
            return 0;
        }

        $tier = $tiers->tierFor($change->after, $placed);

        return PlacementReveal::query()->insertOrIgnore(array_map(fn (int $userId): array => [
            'user_id' => $userId,
            'rating_id' => $rating->id,
            'game' => $rating->game,
            'mode' => $rating->mode,
            'rating' => $change->after,
            'tier' => $tier,
            'created_at' => now(),
            'updated_at' => now(),
        ], $players));
    }

    /**
     * The oldest reveal this player has not seen, now marked as seen; null
     * when there is none or another request claimed it first.
     */
    public function claim(User $user): ?PlacementReveal
    {
        $reveal = PlacementReveal::query()->where('user_id', $user->id)->whereNull('shown_at')->oldest('id')->first();

        return $reveal !== null && $this->take($reveal) ? $reveal : null;
    }

    /**
     * Marks a reveal as shown, unless another request did since it was read.
     */
    public function take(PlacementReveal $reveal): bool
    {
        return PlacementReveal::query()->whereKey($reveal->id)->whereNull('shown_at')->update(['shown_at' => now()]) === 1;
    }
}
