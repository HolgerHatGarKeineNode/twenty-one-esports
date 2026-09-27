<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a tournament before the draw: a clan lineup its captain
 * entered (`lineup_id`, `members` = the players it fields, substitutes
 * included) or a solo player (`members` = the player). `event_id` is the
 * signed consent of the person who entered it (App\Support\Tournaments\TournamentSignups);
 * a withdrawal keeps the row and adds its own signed event. An entry an
 * organizer or admin removed before the draw keeps its row and consent too,
 * marked `removed_at` (App\Support\Tournaments\TournamentModeration).
 *
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property int|null $lineup_id
 * @property string $name
 * @property list<int> $members
 * @property int|null $event_id
 * @property Carbon|null $withdrawn_at
 * @property int|null $withdraw_event_id
 * @property Carbon|null $removed_at
 * @property int|null $removed_by_id
 * @property string|null $removal_reason
 * @property Carbon|null $reconfirm_since a rules change since sign-up: waiting for a new consent
 * @property int|null $reconfirm_event_id the new consent against the current version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $user
 * @property-read Lineup|null $lineup
 * @property-read NostrEvent|null $event
 */
#[Fillable(['tournament_id', 'user_id', 'lineup_id', 'name', 'members', 'event_id', 'withdrawn_at', 'withdraw_event_id', 'removed_at', 'removed_by_id', 'removal_reason', 'reconfirm_since', 'reconfirm_event_id'])]
class TournamentSignup extends Model
{
    protected function casts(): array
    {
        return ['members' => 'array', 'withdrawn_at' => 'datetime', 'removed_at' => 'datetime', 'reconfirm_since' => 'datetime'];
    }

    /**
     * Entries that count: neither pulled out nor removed.
     *
     * @param  Builder<TournamentSignup>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('withdrawn_at')->whereNull('removed_at');
    }

    public function needsReconfirm(): bool
    {
        return $this->reconfirm_since !== null;
    }

    public function isSolo(): bool
    {
        return $this->lineup_id === null;
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }
}
