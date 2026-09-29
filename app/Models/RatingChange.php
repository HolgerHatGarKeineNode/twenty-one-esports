<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one result did to one rating: before, after and the delta, plus the
 * score from this entity's side (1, 0.5, 0). `source` is `chess` (a chess
 * game id), `series` (a series match id) or `board` (a game of a board game
 * other than chess, plan "Mühle und Dame" P5; its rating row names the
 * board game); `match_number` is the league match number shown next to it.
 *
 * A correction of a rated result (RatingService::correct()) marks the
 * result's rows `reverted_at` and may write the corrected ones as the next
 * `revision`. Reverted rows are the audit trail only: the `live` scope keeps
 * them out of every query, so pages, stats and hashrate read what counts
 * now. Read them with `withoutGlobalScope(RatingChange::LIVE)`.
 *
 * @property int $id
 * @property int $rating_id
 * @property int|null $opponent_rating_id
 * @property 'chess'|'series'|'board' $source
 * @property int $source_id
 * @property int|null $match_number
 * @property float $score
 * @property int $before
 * @property int $after
 * @property int $delta
 * @property int $results_before
 * @property int $revision
 * @property Carbon|null $reverted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Rating $rating
 */
#[Fillable(['rating_id', 'opponent_rating_id', 'source', 'source_id', 'match_number', 'score', 'before', 'after', 'delta', 'results_before', 'revision'])]
class RatingChange extends Model
{
    public const CHESS = 'chess';

    public const SERIES = 'series';

    /** A board game other than chess (plan "Mühle und Dame", P5). */
    public const BOARD = 'board';

    /** The global scope that hides reverted rows. */
    public const LIVE = 'live';

    protected static function booted(): void
    {
        static::addGlobalScope(self::LIVE, fn (Builder $query) => $query->whereNull($query->qualifyColumn('reverted_at')));
    }

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'before' => 'integer',
            'after' => 'integer',
            'delta' => 'integer',
            'results_before' => 'integer',
            'match_number' => 'integer',
            'revision' => 'integer',
            'reverted_at' => 'datetime',
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
