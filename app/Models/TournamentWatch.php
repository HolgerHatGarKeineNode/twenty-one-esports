<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player asked to hear of every new tournament of one game (plan
 * "RL-Startseite", P2: the prize band's "Notify me of new Rocket League
 * tournaments"). Told once per tournament when it opens for sign-up
 * (App\Jobs\NotifyTournamentWatchers, NotificationKind::NewTournament);
 * deleting the row, or switching the kind off in the settings, stops it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $game
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'game'])]
class TournamentWatch extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
