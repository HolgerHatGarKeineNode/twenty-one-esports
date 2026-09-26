<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a tournament's director log (TOURNAMENT-FORMATS.md, section
 * 6): who entered or corrected which result, and when. `replaced` is the
 * result it replaced (null for a first entry). Append-only: a row is never
 * changed or deleted, so a correction is always a new row next to the old one.
 *
 * `result` has the shape of TournamentMatch::$result.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $tournament_match_id
 * @property int|null $user_id
 * @property string $user_name
 * @property array<string, mixed> $result
 * @property array<string, mixed>|null $replaced
 * @property Carbon $created_at
 * @property-read TournamentMatch $match
 * @property-read User|null $user
 */
#[Fillable(['tournament_id', 'tournament_match_id', 'user_id', 'user_name', 'result', 'replaced', 'created_at'])]
class TournamentResultEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('The director log is append-only.'));
        static::deleting(fn (): never => throw new LogicException('The director log is append-only.'));
    }

    protected function casts(): array
    {
        return ['result' => 'array', 'replaced' => 'array', 'created_at' => 'datetime'];
    }

    public function isCorrection(): bool
    {
        // Not `previous`: Eloquent's own protected $previous would shadow a column of that name here.
        return $this->replaced !== null;
    }

    /**
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'tournament_match_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
