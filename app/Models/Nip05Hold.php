<?php

namespace App\Models;

use App\Support\Nostr\Nip05Names;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A NIP-05 name nobody may claim for now (P47 security audit F2,
 * {@see Nip05Names}): revoked by an admin (until an
 * admin lifts it) or given up (for the change period, except by the key
 * that held it). Keyed by name, so no account's deletion undoes it.
 *
 * @property int $id
 * @property string $name
 * @property string $reason `revoked` | `released`
 * @property string|null $pubkey the key that held the name
 * @property int|null $user_id
 * @property Carbon|null $held_until null: until an admin lifts it
 * @property int|null $created_by_id the admin who revoked
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['name', 'reason', 'pubkey', 'user_id', 'held_until', 'created_by_id', 'lifted_at', 'lifted_by_id'])]
class Nip05Hold extends Model
{
    public const REVOKED = 'revoked';

    public const RELEASED = 'released';

    protected function casts(): array
    {
        return [
            'held_until' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Holds in force now: not lifted, and without an end or with one ahead.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')->where(fn (Builder $query) => $query->whereNull('held_until')->orWhere('held_until', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
