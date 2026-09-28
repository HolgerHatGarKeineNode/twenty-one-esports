<?php

namespace App\Models;

use App\Support\Rating\RatingSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change of the draft for the next Block 0 (AdminSeason, P35, P43):
 * who changed it, the full draft afterwards and what changed. The newest row
 * is the draft (RatingSettings::draft(), ChainDraft::stored()).
 *
 * @property int $id
 * @property int|null $changed_by_id
 * @property string $changed_by_pubkey
 * @property array{rating: array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null}, tiers: array<string, int>, hashrate: array{win: int, draw: int, loss: int, team_win_bonus: int}, chain?: array<string, mixed>} $values the rating draft, and from P43 on the chain draft under `chain` (ChainDraft)
 * @property array<string, array{0: int|string|null, 1: int|string|null}> $changes dot path => [before, after]
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $changedBy
 */
#[Fillable(['changed_by_id', 'changed_by_pubkey', 'values', 'changes'])]
class SeasonSettingChange extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => RatingSettings::forget());
        static::deleted(fn () => RatingSettings::forget());
    }

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
