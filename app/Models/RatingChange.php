<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one result did to one rating: before, after and the delta, plus the
 * score from this entity's side (1, 0.5, 0). `source` is `chess` (a chess
 * game id) or `series` (a series match id); `match_number` is the league
 * match number shown next to it.
 *
 * @property int $id
 * @property int $rating_id
 * @property int|null $opponent_rating_id
 * @property 'chess'|'series' $source
 * @property int $source_id
 * @property int|null $match_number
 * @property float $score
 * @property int $before
 * @property int $after
 * @property int $delta
 * @property int $results_before
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Rating $rating
 */
#[Fillable(['rating_id', 'opponent_rating_id', 'source', 'source_id', 'match_number', 'score', 'before', 'after', 'delta', 'results_before'])]
class RatingChange extends Model
{
    public const CHESS = 'chess';

    public const SERIES = 'series';

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'before' => 'integer',
            'after' => 'integer',
            'delta' => 'integer',
            'results_before' => 'integer',
            'match_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }
}
