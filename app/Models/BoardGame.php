<?php

namespace App\Models;

use App\Enums\BoardGameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One game of a board game other than chess (nine men's morris, checkers;
 * plan "Mühle und Dame"), next to chess and not on its tables. The server
 * holds the position and the clocks; App\Support\Board\BoardGameService is
 * the only writer. Not to be mixed up with App\Games\BoardGame, the registry
 * entry of such a game (its name, modes and rules).
 *
 * Clocks as in chess: `white_ms` / `black_ms` are the time each side had
 * left when the side to move started thinking (`turn_started_ms`, Unix ms).
 * The side to move's real time left is its value minus the time since then;
 * the other value is exact. Before both first moves no clock runs.
 *
 * @property int $id
 * @property string $game registry slug of the board game
 * @property string $mode
 * @property int|null $white_id null once the player deleted the account
 * @property int|null $black_id null once the player deleted the account
 * @property BoardGameStatus $status
 * @property '1-0'|'0-1'|'1/2-1/2'|null $result
 * @property string|null $end_reason App\Enums\BoardEndReason or a reason code of the game's rules
 * @property string $position as the game's rules serialize it
 * @property 'w'|'b' $turn
 * @property int $ply
 * @property int $initial_ms
 * @property int $increment_ms
 * @property int $white_ms
 * @property int $black_ms
 * @property int $turn_started_ms
 * @property int|null $deadline_ms
 * @property 'w'|'b'|null $draw_offer
 * @property int $version
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $white
 * @property-read User|null $black
 * @property-read Collection<int, BoardMove> $moves
 */
#[Fillable(['game', 'mode', 'white_id', 'black_id', 'status', 'result', 'end_reason', 'position', 'turn', 'ply', 'initial_ms', 'increment_ms',
    'white_ms', 'black_ms', 'turn_started_ms', 'deadline_ms', 'draw_offer', 'version', 'ended_at'])]
class BoardGame extends Model
{
    protected function casts(): array
    {
        return [
            'status' => BoardGameStatus::class,
            'ply' => 'integer',
            'initial_ms' => 'integer',
            'increment_ms' => 'integer',
            'white_ms' => 'integer',
            'black_ms' => 'integer',
            'turn_started_ms' => 'integer',
            'deadline_ms' => 'integer',
            'version' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function white(): BelongsTo
    {
        return $this->belongsTo(User::class, 'white_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function black(): BelongsTo
    {
        return $this->belongsTo(User::class, 'black_id');
    }

    /**
     * @return HasMany<BoardMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(BoardMove::class)->orderBy('ply');
    }

    /**
     * Games this user plays, either side.
     *
     * @param  Builder<BoardGame>  $query
     */
    #[Scope]
    protected function playedBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id));
    }

    public function isActive(): bool
    {
        return $this->status === BoardGameStatus::Active;
    }

    /**
     * Both sides have made their first move; from here on the clocks run.
     */
    public function clocksRunning(): bool
    {
        return $this->ply >= 2;
    }

    /**
     * 'w' or 'b' for a player of this game, null for everyone else.
     *
     * @return 'w'|'b'|null
     */
    public function colorOf(?User $user): ?string
    {
        return match ($user?->id) {
            null => null,
            $this->white_id => 'w',
            $this->black_id => 'b',
            default => null,
        };
    }

    /**
     * @param  'w'|'b'  $color
     */
    public function player(string $color): ?User
    {
        return $color === 'w' ? $this->white : $this->black;
    }
}
