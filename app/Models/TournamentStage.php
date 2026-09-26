<?php

namespace App\Models;

use App\Enums\TournamentFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stage of a tournament: one for most formats, two for Two Stage (the
 * groups, then the final stage). `format` is the system this stage plays.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $number
 * @property TournamentFormat $format
 * @property string $status pending|running|done
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TournamentRound> $rounds
 */
#[Fillable(['tournament_id', 'number', 'format', 'status'])]
class TournamentStage extends Model
{
    protected function casts(): array
    {
        return ['number' => 'integer', 'format' => TournamentFormat::class];
    }

    /**
     * @return HasMany<TournamentRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(TournamentRound::class)->orderBy('number');
    }
}
