<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player searching a game of one board game other than chess (plan "Mühle
 * und Dame", P5), one row per player. `rating` is the player's casual rating
 * of that game when they joined, the centre of the pairing range; a rated
 * search (P6) pairs only rated searches.
 *
 * @property int $id
 * @property int $user_id
 * @property string $game registry slug of the board game
 * @property string $mode
 * @property int $rating
 * @property bool $rated
 * @property Carbon $joined_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'game', 'mode', 'rating', 'rated', 'joined_at'])]
class BoardQueueEntry extends Model
{
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'rated' => 'boolean',
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
