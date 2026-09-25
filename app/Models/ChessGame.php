<?php

namespace App\Models;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use Database\Factories\ChessGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One live chess game between two players. The server holds the position and
 * the clocks; App\Support\Chess\ChessGameService is the only writer.
 *
 * Clocks: `white_ms` / `black_ms` are the time each side had left when the
 * side to move started thinking (`turn_started_ms`, Unix ms). The side to
 * move's real time left is its value minus the time since then; the other
 * value is exact. Before both first moves no clock runs.
 *
 * @property int $id
 * @property string $mode
 * @property bool $rated
 * @property int $white_id
 * @property int $black_id
 * @property ChessGameStatus $status
 * @property string|null $result
 * @property ChessEndReason|null $end_reason
 * @property string|null $start_fen null = the normal start position
 * @property string $fen
 * @property int $ply
 * @property int $initial_ms
 * @property int $increment_ms
 * @property int $white_ms
 * @property int $black_ms
 * @property int $turn_started_ms
 * @property int|null $deadline_ms
 * @property 'w'|'b'|null $draw_offer
 * @property 'w'|'b'|null $rematch_offer
 * @property int|null $rematch_of_id
 * @property int|null $rematch_id
 * @property int $version
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $white
 * @property-read User $black
 * @property-read Collection<int, ChessMove> $moves
 * @property-read ChessGame|null $rematch
 */
#[Fillable(['mode', 'rated', 'white_id', 'black_id', 'status', 'result', 'end_reason', 'start_fen', 'fen', 'ply', 'initial_ms', 'increment_ms',
    'white_ms', 'black_ms', 'turn_started_ms', 'deadline_ms', 'draw_offer', 'rematch_offer', 'rematch_of_id', 'rematch_id', 'version', 'ended_at'])]
class ChessGame extends Model
{
    /** @use HasFactory<ChessGameFactory> */
    use HasFactory;

    public const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    protected function casts(): array
    {
        return [
            'rated' => 'boolean',
            'status' => ChessGameStatus::class,
            'end_reason' => ChessEndReason::class,
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
     * @return HasMany<ChessMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(ChessMove::class)->orderBy('ply');
    }

    /**
     * @return BelongsTo<ChessGame, $this>
     */
    public function rematch(): BelongsTo
    {
        return $this->belongsTo(ChessGame::class, 'rematch_id');
    }

    public function startFen(): string
    {
        return $this->start_fen ?? self::START_FEN;
    }

    public function isActive(): bool
    {
        return $this->status === ChessGameStatus::Active;
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
     * @return 'w'|'b'
     */
    public function turn(): string
    {
        return explode(' ', $this->fen)[1] === 'b' ? 'b' : 'w';
    }

    /**
     * Both sides have made their first move; from here on the clocks run.
     */
    public function clocksRunning(): bool
    {
        return $this->ply >= 2;
    }

    public function number(): string
    {
        return '#'.$this->id;
    }
}
