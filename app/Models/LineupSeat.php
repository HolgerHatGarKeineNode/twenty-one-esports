<?php

namespace App\Models;

use App\Enums\LineupRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player the owner placed in a lineup. Only active clan members are placed,
 * and their Clan Membership (kind 12150) is the consent (NIP rev. 6), so
 * `accepted_at` is the time of placing. Seats from before rev. 6 kept the
 * time the player's membership first named the lineup.
 *
 * @property int $id
 * @property int $lineup_id
 * @property int $user_id
 * @property LineupRole $role
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lineup $lineup
 * @property-read User $user
 */
#[Fillable(['lineup_id', 'user_id', 'role', 'accepted_at'])]
class LineupSeat extends Model
{
    protected function casts(): array
    {
        return [
            'role' => LineupRole::class,
            'accepted_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An active lineup player: placed (`accepted_at`) and still a member of the lineup's clan.
     */
    public function isActive(int $clanId): bool
    {
        return $this->accepted_at !== null && $this->user->clanMember?->clan_id === $clanId;
    }
}
