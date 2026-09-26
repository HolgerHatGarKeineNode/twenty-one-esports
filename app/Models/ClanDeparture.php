<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player who left a clan ("Former" tab on the manage page). The row
 * outlives the clan (P7d gate, Low A): the own-clan guard of the trust
 * admin reads it for the whole season, so `clan_id` is the id the clan had,
 * without a foreign key, and the address and name stay with it.
 *
 * @property int $id
 * @property int $clan_id
 * @property string|null $clan_address
 * @property string|null $clan_name
 * @property int $user_id
 * @property string $reason left|removed|switched
 * @property Carbon $left_at
 * @property-read User $user
 */
#[Fillable(['clan_id', 'clan_address', 'clan_name', 'user_id', 'reason', 'left_at'])]
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
