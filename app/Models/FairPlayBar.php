<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin barred a player from rated play by hand (fair play,
 * App\Support\FairPlay\Bars). It runs until `until` unless it is lifted
 * before (`lifted_at`); the row stays, so who barred, who lifted and why can
 * always be read. `reason` is internal: players never see it. Barring the
 * same player again writes a new row.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey
 * @property string $reason
 * @property Carbon $until
 * @property int|null $barred_by_id
 * @property string $barred_by_pubkey
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by_id
 * @property string|null $lifted_by_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'pubkey', 'reason', 'until', 'barred_by_id', 'barred_by_pubkey'])]
class FairPlayBar extends Model
{
    protected function casts(): array
    {
        return [
            'until' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * The bars in force now: not lifted, and their end still ahead.
     *
     * @param  Builder<FairPlayBar>  $query
     * @return Builder<FairPlayBar>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')->where('until', '>', now());
    }

    public function isRunning(): bool
    {
        return $this->lifted_at === null && $this->until->isFuture();
    }
}
