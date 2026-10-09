<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player's season Elo in Hyperbitcoinization (plan "Hyperbitcoinization", P5): one row per season, kind and
 * player. `duel` rates the 1v1 matches, `team` the clan matches (a team's rating is the average of its members',
 * and each member gets their own change). Free-for-all matches score points instead (hyper_seats.points).
 * App\Support\Hyper\HyperSeason is the only writer.
 *
 * @property int $id
 * @property string $season
 * @property 'duel'|'team' $kind
 * @property int $user_id
 * @property int $rating
 * @property int $results
 * @property int $wins
 * @property int $losses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['season', 'kind', 'user_id', 'rating', 'results', 'wins', 'losses'])]
class HyperRating extends Model
{
    public const DUEL = 'duel';

    public const TEAM = 'team';

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

    /**
     * @return HasMany<HyperRatingChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(HyperRatingChange::class);
    }
}
