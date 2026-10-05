<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player a captain named for a chess team match (NIP rev. 9.22, "Chess
 * team matches"; App\Support\Chess\ChessTeamMatches). Until the lineup lock
 * `board` is null and only the player's own side sees the row; at the lock
 * the league writes the board (1..boards) and the rapid rating it ordered by.
 *
 * @property int $id
 * @property int $series_match_id
 * @property string $side challenger|challenged
 * @property int|null $user_id null once the account is deleted
 * @property int|null $board
 * @property int|null $rating the rapid rating the lock ordered by
 * @property string|null $rating_pool rated|casual|start: where that rating came from
 * @property int|null $rating_results results on that ladder (the first tiebreak)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SeriesMatch $seriesMatch
 * @property-read User|null $user
 */
#[Fillable(['series_match_id', 'side', 'user_id', 'board', 'rating', 'rating_pool', 'rating_results'])]
class SeriesMatchBoard extends Model
{
    protected function casts(): array
    {
        return [
            'board' => 'integer',
            'rating' => 'integer',
            'rating_results' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SeriesMatch, $this>
     */
    public function seriesMatch(): BelongsTo
    {
        return $this->belongsTo(SeriesMatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
