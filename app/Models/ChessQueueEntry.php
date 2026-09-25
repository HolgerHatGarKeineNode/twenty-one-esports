<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player waiting in the blitz queue (one row per player). `rating` is the
 * number the pairing range is centred on; 1000 for everyone until Elo (P7).
 *
 * @property int $id
 * @property int $user_id
 * @property string $mode
 * @property bool $rated
 * @property int $rating
 * @property Carbon $joined_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'mode', 'rated', 'rating', 'joined_at'])]
class ChessQueueEntry extends Model
{
    protected function casts(): array
    {
        return [
            'rated' => 'boolean',
            'rating' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
