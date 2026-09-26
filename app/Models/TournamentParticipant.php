<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a tournament: a player (chess, RL 1v1), a lineup (RL 2v2/3v3)
 * or a drawn mix team (P8b). `rating` is the Elo it was seeded with, `seed`
 * and `group` are set when the bracket is generated.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property int|null $lineup_id
 * @property string $name
 * @property int $rating
 * @property int|null $seed
 * @property int|null $group
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 */
#[Fillable(['tournament_id', 'user_id', 'lineup_id', 'name', 'rating', 'seed', 'group'])]
class TournamentParticipant extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'seed' => 'integer', 'group' => 'integer'];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}
