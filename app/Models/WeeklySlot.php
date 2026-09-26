<?php

namespace App\Models;

use App\Support\Engagement\WeeklySlots;
use Database\Factories\WeeklySlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring weekly event an admin defines (P10), e.g. "Blitz night,
 * Wednesday 20:00". The scheduler turns it into dated SlotEvents
 * ({@see WeeklySlots}).
 *
 * @property int $id
 * @property string $title
 * @property string $game
 * @property string $mode
 * @property int $weekday ISO weekday, 1 = Monday … 7 = Sunday
 * @property string $time wall-clock `HH:MM` in `timezone`
 * @property string $timezone
 * @property int $duration_minutes
 * @property bool $active
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
#[Fillable(['title', 'game', 'mode', 'weekday', 'time', 'timezone', 'duration_minutes', 'active', 'created_by_id'])]
class WeeklySlot extends Model
{
    /** @use HasFactory<WeeklySlotFactory> */
    use HasFactory;

    public const TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'duration_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<SlotEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SlotEvent::class);
    }
}
