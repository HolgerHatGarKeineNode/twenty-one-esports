<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A match of a tournament bracket (App\Support\Tournaments\Engine\BracketMatch
 * stored). `key` is the engine's match key, stable within the tournament;
 * `bracket` names the part (main, upper, lower, grand-final, reset,
 * third-place, heat, board, bye). The result is entered in P8b.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $tournament_round_id
 * @property string $key
 * @property int|null $group
 * @property string $bracket
 * @property int $position
 * @property bool $if_needed
 * @property string $status waiting|ready|done|skipped
 * @property array<string, mixed>|null $result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TournamentRound $round
 * @property-read Collection<int, TournamentMatchSlot> $slots
 */
#[Fillable(['tournament_id', 'tournament_round_id', 'key', 'group', 'bracket', 'position', 'if_needed', 'status', 'result'])]
class TournamentMatch extends Model
{
    protected function casts(): array
    {
        return ['group' => 'integer', 'position' => 'integer', 'if_needed' => 'boolean', 'result' => 'array'];
    }

    /**
     * @return BelongsTo<TournamentRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(TournamentRound::class, 'tournament_round_id');
    }

    /**
     * @return HasMany<TournamentMatchSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(TournamentMatchSlot::class)->orderBy('slot');
    }
}
