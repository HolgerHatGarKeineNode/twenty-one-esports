<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player an organizer or admin blocked from signing up for one tournament
 * again (App\Support\Tournaments\TournamentModeration). Unblocking deletes
 * the row; the moderation log keeps both steps.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $user_id
 * @property int|null $added_by_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['tournament_id', 'user_id', 'added_by_id', 'reason'])]
class TournamentBan extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
