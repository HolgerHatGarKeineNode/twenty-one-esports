<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player waiting in the live chess queue (one row per player). `rating`
 * is the number the pairing range is centred on; 1000 for everyone until
 * Elo (P7). `mode` is the first choice, `modes` every mode the entry takes
 * ("either", P2 of plan "Schach Rapid und Clan"); null = only `mode`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $mode
 * @property list<string>|null $modes
 * @property bool $rated
 * @property int $rating
 * @property Carbon $joined_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'mode', 'modes', 'rated', 'rating', 'joined_at'])]
class ChessQueueEntry extends Model
{
    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'rated' => 'boolean',
            'rating' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * Every mode this entry takes, its first choice first.
     *
     * @return list<string>
     */
    public function takes(): array
    {
        return array_values(array_unique([$this->mode, ...($this->modes ?? [])]));
    }

    /** Whether this entry takes more than one mode ("either"). */
    public function takesEither(): bool
    {
        return count($this->takes()) > 1;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
