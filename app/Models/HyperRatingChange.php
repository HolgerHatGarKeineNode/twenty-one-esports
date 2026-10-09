<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one rated Hyperbitcoinization match did to one player's season Elo (plan "Hyperbitcoinization", P5):
 * before, after, the delta and the player's score (1 won, 0 lost; a forfeit is always 0). One row per rating and
 * match (unique), so a match is never rated twice.
 *
 * @property int $id
 * @property int $hyper_rating_id
 * @property int $hyper_match_id
 * @property float $score
 * @property int $before
 * @property int $after
 * @property int $delta
 * @property int $results_before
 * @property Carbon|null $created_at
 * @property-read HyperRating $rating
 * @property-read HyperMatch $match
 */
#[Fillable(['hyper_rating_id', 'hyper_match_id', 'score', 'before', 'after', 'delta', 'results_before', 'created_at'])]
class HyperRatingChange extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['score' => 'float', 'before' => 'integer', 'after' => 'integer', 'delta' => 'integer', 'results_before' => 'integer'];
    }

    /**
     * @return BelongsTo<HyperRating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(HyperRating::class, 'hyper_rating_id');
    }

    /**
     * @return BelongsTo<HyperMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(HyperMatch::class, 'hyper_match_id');
    }
}
