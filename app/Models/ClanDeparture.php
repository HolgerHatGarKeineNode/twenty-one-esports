<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player who left a clan ("Former" tab on the manage page). The row
 * outlives the clan (P7d gate, Low A) and the leaver's account (P7e): the
 * own-clan guard of the trust admin reads it for the whole season, so
 * `clan_id` is the id the clan had, without a foreign key, the address and
 * name stay with it, and the leaver's pubkey stays when `user_id` is gone.
 *
 * @property int $id
 * @property int $clan_id
 * @property string|null $clan_address
 * @property string|null $clan_name
 * @property int|null $user_id
 * @property string|null $pubkey
 * @property string $reason left|removed|switched|deleted
 * @property Carbon $left_at
 * @property-read User|null $user
 */
#[Fillable(['clan_id', 'clan_address', 'clan_name', 'user_id', 'pubkey', 'reason', 'left_at'])]
class ClanDeparture extends Model
{
    protected function casts(): array
    {
        return [
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
