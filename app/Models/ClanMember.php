<?php

namespace App\Models;

use App\Enums\ClanRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An active clan member: listed in the clan event and accepted with the
 * player's own Clan Membership (kind 12150). `user_id` is unique.
 *
 * @property int $id
 * @property int $clan_id
 * @property int $user_id
 * @property ClanRole $role
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clan $clan
 * @property-read User $user
 */
#[Fillable(['clan_id', 'user_id', 'role', 'joined_at'])]
class ClanMember extends Model
{
    protected function casts(): array
    {
        return [
            'role' => ClanRole::class,
            'joined_at' => 'datetime',
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
}
