<?php

namespace App\Models;

use App\Support\Engagement\Placements;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The rank a player placed into with their fifth rated result in a ladder
 * (P10, "Placement"). Shown once: `shown_at` is claimed by
 * {@see Placements::claim()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $rating_id
 * @property string $game
 * @property string $mode
 * @property int $rating
 * @property string $tier NIP tier token, e.g. `gold-2`
 * @property Carbon|null $shown_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'rating_id', 'game', 'mode', 'rating', 'tier', 'shown_at'])]
class PlacementReveal extends Model
{
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'shown_at' => 'datetime',
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
     * The tier token split for the rank badge: `grand-champion-3` =>
     * ['grand-champion', 3].
     *
     * @return array{0: string, 1: int}
     */
    public function badge(): array
    {
        if (preg_match('/^(.+)-([1-3])$/', $this->tier, $parts) !== 1) {
            return [$this->tier, 1];
        }

        return [$parts[1], (int) $parts[2]];
    }
}
