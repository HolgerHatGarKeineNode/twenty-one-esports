<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated occurrence of a weekly slot, made by the scheduler. Unique per
 * slot and start, so the scheduler may run as often as it likes.
 *
 * @property int $id
 * @property int $weekly_slot_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WeeklySlot $slot
 */
#[Fillable(['weekly_slot_id', 'starts_at', 'ends_at'])]
class SlotEvent extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WeeklySlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(WeeklySlot::class, 'weekly_slot_id');
    }

    public function isLive(): bool
    {
        return $this->starts_at->lte(now()) && $this->ends_at->gt(now());
    }
}
