<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One automatic reminder that went out to a player of a waiting tournament
 * match (P18, slice 5, App\Support\Tournaments\TournamentReminders). The
 * unique key (subject, state, due_at, minutes_before, user_id) is what
 * makes it go out once.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $tournament_match_id
 * @property int $user_id
 * @property string $subject `series:<id>`, `chess:<id>` or `match:<id>`
 * @property string $state the waiting state (App\Support\Tournaments\MatchWait)
 * @property Carbon $due_at the automatic decision it reminded of
 * @property int $minutes_before the reminder point (`esports.tournaments.reminders`)
 * @property Carbon $created_at
 */
#[Fillable(['tournament_id', 'tournament_match_id', 'user_id', 'subject', 'state', 'due_at', 'minutes_before', 'created_at'])]
class TournamentReminder extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'minutes_before' => 'integer', 'created_at' => 'datetime'];
    }
}
