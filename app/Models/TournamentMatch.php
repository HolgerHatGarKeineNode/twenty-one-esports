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
 * carries a series' goals per game. P18: `by` = `league` for a match the
 * league decided itself (a withdrawn side, the double no-show rule, the
 * higher seed after the last drawn replay), with `forfeit`, `double_loss`
 * and `decided` (`withdrawn`, `noshow`, `seed`). The normal match it is played as is
 * {@see seriesMatch()} (Rocket League) or {@see chessGame()} (chess), or
 * {@see boardGame()} for a board game other than chess (plan "Mühle und Dame", P5).
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
 * @property array{gate?: array<string, mixed>|null, clans?: array<string, string>, ladder?: string|null}|null $pairing what the league read at the pairing of a director chess match (the ladder since P8c)
 * @property array{was: array<string, mixed>, reason: string, at: string, user_id: int|null, name: string}|null $held a played result set aside after a correction it depended on (P18, TournamentControl); the match waits for a decision
 * @property int|null $replaced_through the last series or chess game of this match that no longer counts (voided or superseded by the league, P18); only a newer one is played
 * @property array{by: int, proposals: list<int>, respond_by: int, agreed_at: int|null, accepted_by: int|null}|null $schedule a casual cup series match's proposed and agreed start (P25 S3, CupSchedules)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TournamentRound $round
 * @property-read Collection<int, TournamentMatchSlot> $slots
 * @property-read Tournament $tournament
 * @property-read SeriesMatch|null $seriesMatch
 * @property-read ChessGame|null $chessGame
 * @property-read BoardGame|null $boardGame
 */
#[Fillable(['tournament_id', 'tournament_round_id', 'key', 'group', 'bracket', 'position', 'if_needed', 'status', 'result', 'pairing', 'held', 'replaced_through', 'schedule'])]
class TournamentMatch extends Model
{
    protected function casts(): array
    {
        return ['group' => 'integer', 'position' => 'integer', 'if_needed' => 'boolean', 'result' => 'array', 'pairing' => 'array', 'held' => 'array', 'replaced_through' => 'integer', 'schedule' => 'array'];
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
     * The current series: the latest attempt (an admin may void one, P18).
     *
     * @return HasOne<SeriesMatch, $this>
     */
    public function seriesMatch(): HasOne
    {
        return $this->hasOne(SeriesMatch::class)->latestOfMany();
    }

    /**
     * @return HasOne<ChessGame, $this>
     */
    public function chessGame(): HasOne
    {
        return $this->hasOne(ChessGame::class)->latestOfMany();
    }

    /**
     * The latest game of a board game match (nine men's morris, checkers).
     *
     * @return HasOne<BoardGame, $this>
     */
    public function boardGame(): HasOne
    {
        return $this->hasOne(BoardGame::class)->latestOfMany();
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
            (bool) ($result['double_loss'] ?? false),
        );
    }

    /**
     * Whether a series or chess game (by id) is one the league voided or
     * superseded for this match (P18): its end moves nothing here.
     */
    public function isReplaced(?int $id): bool
    {
        return $id !== null && $this->replaced_through !== null && $id <= $this->replaced_through;
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
