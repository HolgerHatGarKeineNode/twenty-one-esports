<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a tournament's moderation log: who edited the tournament,
 * removed an entry, or blocked or unblocked a player, when, and why.
 * `subject` is the entry or player it concerns, `details` the changed
 * fields (`field => [old, new]`) of an edit. Append-only, like the director
 * log: a row is never changed or deleted.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property string $user_name
 * @property 'edited'|'removed'|'blocked'|'unblocked' $action
 * @property int|null $tournament_signup_id
 * @property string|null $subject
 * @property string|null $reason
 * @property array<string, array{0: mixed, 1: mixed}>|null $details
 * @property Carbon $created_at
 */
#[Fillable(['tournament_id', 'user_id', 'user_name', 'action', 'tournament_signup_id', 'subject', 'reason', 'details', 'created_at'])]
class TournamentModerationEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('The moderation log is append-only.'));
        static::deleting(fn (): never => throw new LogicException('The moderation log is append-only.'));
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
