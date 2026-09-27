<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player searching a casual 1v1 right now (P23, one row per player;
 * App\Support\Series\CasualQueue). `platform` is what they play on for this
 * search, `crossplay` whether they accept an opponent on another platform.
 *
 * @property int $id
 * @property int $user_id
 * @property string $game
 * @property string $mode
 * @property Platform $platform
 * @property bool $crossplay
 * @property Carbon $joined_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'game', 'mode', 'platform', 'crossplay', 'joined_at'])]
class SeriesQueueEntry extends Model
{
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'crossplay' => 'boolean',
            'joined_at' => 'datetime',
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
