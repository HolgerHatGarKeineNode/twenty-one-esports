<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An admin linked a second account to the main account of the same person
 * (P41, App\Support\FairPlay\AccountLinks). While `unlinked_at` is null the
 * linked account plays no rated match and wins no prize. Unlinking fills the
 * unlink columns; the row stays, so who linked, who unlinked and why can
 * always be read. Linking the same account again writes a new row.
 *
 * @property int $id
 * @property int|null $main_user_id
 * @property string $main_pubkey
 * @property int|null $linked_user_id
 * @property string $linked_pubkey
 * @property int|null $linked_by_id
 * @property string $linked_by_pubkey
 * @property string $reason
 * @property Carbon|null $unlinked_at
 * @property int|null $unlinked_by_id
 * @property string|null $unlinked_by_pubkey
 * @property string|null $unlink_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $main
 * @property-read User|null $linked
 * @property-read User|null $linkedBy
 * @property-read User|null $unlinkedBy
 */
#[Fillable(['main_user_id', 'main_pubkey', 'linked_user_id', 'linked_pubkey', 'linked_by_id', 'linked_by_pubkey', 'reason'])]
class AccountLink extends Model
{
    protected function casts(): array
    {
        return [
            'unlinked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AccountLink>  $query
     * @return Builder<AccountLink>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unlinked_at');
    }

    public function isActive(): bool
    {
        return $this->unlinked_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function main(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_id');
    }

    /**
     * @return HasMany<FairPlayVoid, $this>
     */
    public function voids(): HasMany
    {
        return $this->hasMany(FairPlayVoid::class);
    }
}
