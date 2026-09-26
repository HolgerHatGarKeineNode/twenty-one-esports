<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A time step of a stage: its matches are played side by side. A director
 * closes it once every result is in (P8b); a closed round's results are locked.
 *
 * @property int $id
 * @property int $tournament_stage_id
 * @property int $number
 * @property string $status open|closed
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TournamentStage $stage
 * @property-read Collection<int, TournamentMatch> $matches
 */
#[Fillable(['tournament_stage_id', 'number', 'status', 'closed_at'])]
class TournamentRound extends Model
{
    protected function casts(): array
    {
        return ['number' => 'integer', 'closed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TournamentStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(TournamentStage::class, 'tournament_stage_id');
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('position');
    }
}
