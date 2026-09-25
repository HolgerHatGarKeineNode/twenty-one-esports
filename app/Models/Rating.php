<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The rating of one entity (a player in chess, a lineup in Rocket League) in
 * one ladder. `rated` is the season ladder that has tiers and goes on Nostr
 * from P7c on; `casual` is permanent, has no season and never a tier.
 * App\Support\Rating\RatingService is the only writer, App\Support\Rating\Ratings
 * reads it for the pages.
 *
 * @property int $id
 * @property 'rated'|'casual' $pool
 * @property string $season '' for casual
 * @property string $game
 * @property string $mode
 * @property string $subject `user:<id>` or `lineup:<id>`
 * @property int|null $user_id
 * @property int|null $lineup_id
 * @property int $rating
 * @property int $results
 * @property int $wins
 * @property int $draws
 * @property int $losses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Lineup|null $lineup
 */
#[Fillable(['pool', 'season', 'game', 'mode', 'subject', 'user_id', 'lineup_id', 'rating', 'results', 'wins', 'draws', 'losses'])]
class Rating extends Model
{
    public const RATED = 'rated';

    public const CASUAL = 'casual';

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'results' => 'integer',
            'wins' => 'integer',
            'draws' => 'integer',
            'losses' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }

    /**
     * @return HasMany<RatingChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(RatingChange::class);
    }
}
