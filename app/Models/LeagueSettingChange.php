<?php

namespace App\Models;

use App\Support\Settings\LeagueSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change of a league setting (P44): who, when, the value in force
 * before and after. `after` null = back to the default of the config. The
 * log is append-only: a row is never changed or deleted, the newest row of a
 * key is its value in force (LeagueSettings).
 *
 * @property int $id
 * @property string $key the config path, one of LeagueSettings::definitions()
 * @property int|string|list<int>|null $before
 * @property int|string|list<int>|null $after
 * @property int|null $changed_by_id
 * @property string $changed_by_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $changedBy
 */
#[Fillable(['key', 'before', 'after', 'changed_by_id', 'changed_by_pubkey'])]
class LeagueSettingChange extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('League setting changes are append-only.'));
        static::deleting(fn () => throw new LogicException('League setting changes are append-only.'));
        static::created(fn () => LeagueSettings::forget());
    }

    protected function casts(): array
    {
        return [
            'before' => 'json',
            'after' => 'json',
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
