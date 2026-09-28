<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One board change of the plan for the season after `after_season_id`
 * (AdminSeason, P38): the full plan afterwards and what changed. The newest
 * row of the newest season is the plan in force (SeasonPlans::current()).
 * Rows are never updated; SeasonPlans is the only writer.
 *
 * @property int $id
 * @property int $after_season_id
 * @property string $slug `season-1`, `season-2`, … (NIP "Season chain")
 * @property string $name
 * @property Carbon $starts_at the planned Block 0, the announcement's `start`
 * @property int $weeks
 * @property int $reset_factor_milli the carry-over factor f in thousandths
 * @property int|null $changed_by_id
 * @property string $changed_by_pubkey
 * @property array<string, array{0: string|int|null, 1: string|int|null}> $changes field => [before, after]
 * @property int|null $announcement_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Season|null $afterSeason
 * @property-read User|null $changedBy
 */
#[Fillable(['after_season_id', 'slug', 'name', 'starts_at', 'weeks', 'reset_factor_milli', 'changed_by_id', 'changed_by_pubkey', 'changes', 'announcement_event_id'])]
class SeasonPlan extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'weeks' => 'integer',
            'reset_factor_milli' => 'integer',
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function afterSeason(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'after_season_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    /** The planned length in seconds. */
    public function lengthSeconds(): int
    {
        return $this->weeks * 604800;
    }
}
