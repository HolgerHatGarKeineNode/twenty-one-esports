<?php

namespace App\Models;

use App\Enums\BoardInviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A challenge to a correspondence game of one board game other than chess
 * (plan "Mühle und Dame", P8), from one player to another, as ChessChallenge
 * for daily chess. Accepting it starts the game with the chosen colours;
 * `rated` asks for a rated game, which needs the rated switch and two
 * Trusted players who list each other at the accept (RatedBoard).
 *
 * @property int $id
 * @property int $challenger_id
 * @property int $challenged_id
 * @property string $game registry slug of the board game
 * @property string $mode
 * @property 'random'|'white'|'black' $color the challenger's colour
 * @property bool $rated
 * @property string|null $message
 * @property BoardInviteStatus $status
 * @property int|null $board_game_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $challenger
 * @property-read User $challenged
 * @property-read BoardGame|null $boardGame
 */
#[Fillable(['challenger_id', 'challenged_id', 'game', 'mode', 'color', 'rated', 'message', 'status', 'board_game_id', 'expires_at'])]
class BoardChallenge extends Model
{
    protected function casts(): array
    {
        return [
            'rated' => 'boolean',
            'status' => BoardInviteStatus::class,
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
     * @return BelongsTo<BoardGame, $this>
     */
    public function boardGame(): BelongsTo
    {
        return $this->belongsTo(BoardGame::class);
    }

    public function isOpen(): bool
    {
        return $this->status === BoardInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
