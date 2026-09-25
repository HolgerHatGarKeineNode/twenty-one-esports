<?php

namespace App\Models;

use App\Enums\JoinRequestStatus;
use Database\Factories\ClanJoinRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player asks to join a clan through a clan link (P6b). League data only:
 * the NIP lets only the owner's key list players (kind 32150), so a captain's
 * yes is stored here and the owner lists the player afterwards
 * (App\Support\Clans\ClanJoinRequests).
 *
 * @property int $id
 * @property int $clan_id
 * @property int $user_id
 * @property int|null $invite_link_id
 * @property JoinRequestStatus $status
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property int|null $clan_invite_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clan $clan
 * @property-read User $user
 * @property-read User|null $decidedBy
 * @property-read ClanInvite|null $clanInvite
 * @property-read InviteLink|null $link
 */
#[Fillable(['clan_id', 'user_id', 'invite_link_id', 'status', 'decided_by_id', 'decided_at', 'clan_invite_id'])]
class ClanJoinRequest extends Model
{
    /** @use HasFactory<ClanJoinRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => JoinRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Clan, $this>
     */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * @return BelongsTo<ClanInvite, $this>
     */
    public function clanInvite(): BelongsTo
    {
        return $this->belongsTo(ClanInvite::class);
    }

    /**
     * @return BelongsTo<InviteLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(InviteLink::class, 'invite_link_id');
    }
}
