<?php

namespace App\Models;

use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invite into a clan lineup. The link `/invites/{ulid}` is what the
 * captain sends; the invitee joins only by signing a Clan Membership.
 *
 * @property int $id
 * @property string $ulid
 * @property int $clan_id
 * @property int $lineup_id
 * @property int|null $inviter_id
 * @property int $invitee_id
 * @property LineupRole $role
 * @property InviteStatus $status
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clan $clan
 * @property-read Lineup $lineup
 * @property-read User|null $inviter
 * @property-read User $invitee
 */
#[Fillable(['clan_id', 'lineup_id', 'inviter_id', 'invitee_id', 'role', 'status', 'responded_at'])]
class ClanInvite extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'role' => LineupRole::class,
            'status' => InviteStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<Clan, $this>
     */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function isPending(): bool
    {
        return $this->status === InviteStatus::Pending;
    }
}
