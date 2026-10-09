<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's Proof of Pong Elo (plan "Proof of Pong", P2): permanent, one row per player, moved by rated live matches
 * only. App\Support\Pong\PongRatings is the only writer; each match keeps the change on its own row.
 *
 * @property int $id
 * @property int $user_id
 * @property int $rating
 * @property int $results
 * @property int $wins
 * @property int $losses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'rating', 'results', 'wins', 'losses'])]
class PongRating extends Model
{
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'rating' => 'integer', 'results' => 'integer', 'wins' => 'integer', 'losses' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
