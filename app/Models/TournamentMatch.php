<?php

namespace App\Models;

use App\Support\Tournaments\Engine\MatchResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A match of a tournament bracket (App\Support\Tournaments\Engine\BracketMatch
 * stored). `key` is the engine's match key, stable within the tournament;
 * `bracket` names the part (main, upper, lower, grand-final, reset,
 * third-place, heat, board, bye).
 *
 * `result` (P8b): the engine's MatchResult as stored ({@see MatchResult()}),
 * plus who produced it: `by` = `players` (the linked series or game ended)
 * or `director` with `user_id`, `name`, `at` and, after a correction,
 * `corrected` (who and when) and `was` (the replaced result's label); `games`
 * carries a series' goals per game. The normal match it is played as is
 * {@see seriesMatch()} (Rocket League) or {@see chessGame()} (chess).
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
 * @property-read Tournament $tournament
 * @property-read SeriesMatch|null $seriesMatch
 * @property-read ChessGame|null $chessGame
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
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return HasOne<SeriesMatch, $this>
     */
    public function seriesMatch(): HasOne
    {
        return $this->hasOne(SeriesMatch::class);
    }

    /**
     * @return HasOne<ChessGame, $this>
     */
    public function chessGame(): HasOne
    {
        return $this->hasOne(ChessGame::class)->latestOfMany();
    }

    /**
     * The stored result as the engine reads it, or null.
     */
    public function matchResult(): ?MatchResult
    {
        $result = $this->result;

        if (! is_array($result) || ! array_key_exists('winner', $result)) {
            return null;
        }

        return new MatchResult(
            $result['winner'] === null ? null : (int) $result['winner'],
            array_values(array_map(floatval(...), (array) ($result['games_won'] ?? []))),
            array_values(array_map(floatval(...), (array) ($result['points'] ?? []))),
            isset($result['ranks']) ? array_values(array_map(intval(...), (array) $result['ranks'])) : null,
        );
    }

    public function isDirectorResult(): bool
    {
        return ($this->result['by'] ?? null) === 'director';
    }

    /**
     * @return HasMany<TournamentMatchSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(TournamentMatchSlot::class)->orderBy('slot');
    }
}
