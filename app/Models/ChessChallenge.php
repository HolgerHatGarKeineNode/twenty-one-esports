<?php

namespace App\Models;

use App\Enums\ChessInviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A daily chess challenge from one player to another (ChessChallenge,
 * ChessOverlays "Daily challenge received"). Casual until Elo exists (P7),
 * so it is league data only: the NIP makes casual games produce no match-flow
 * event (no 2150/2151), only the players' signed game notes.
 *
 * @property int $id
 * @property int $challenger_id
 * @property int $challenged_id
 * @property string $mode
 * @property 'random'|'white'|'black' $color the challenger's colour
 * @property string|null $message
 * @property ChessInviteStatus $status
 * @property int|null $chess_game_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $challenger
 * @property-read User $challenged
 * @property-read ChessGame|null $game
 */
#[Fillable(['challenger_id', 'challenged_id', 'mode', 'color', 'message', 'status', 'chess_game_id', 'expires_at'])]
class ChessChallenge extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ChessInviteStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'challenger_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function challenged(): BelongsTo
    {
        return $this->belongsTo(User::class, 'challenged_id');
    }

    /**
     * @return BelongsTo<ChessGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(ChessGame::class, 'chess_game_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ChessInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
