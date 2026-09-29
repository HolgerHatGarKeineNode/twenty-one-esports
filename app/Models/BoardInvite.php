<?php

namespace App\Models;

use App\Enums\BoardInviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invite to one player for a game of one board game other than chess
 * (plan "Mühle und Dame", P5), as ChessInvite for chess. Accepting it starts
 * a casual game; with `tournament_match_id` it is a "Play your cup match"
 * invite and starts that match's game instead.
 *
 * @property int $id
 * @property int $inviter_id
 * @property int $invitee_id
 * @property string $game registry slug of the board game
 * @property string $mode
 * @property BoardInviteStatus $status
 * @property int|null $board_game_id
 * @property int|null $tournament_match_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read User $invitee
 * @property-read BoardGame|null $boardGame
 * @property-read TournamentMatch|null $tournamentMatch
 */
#[Fillable(['inviter_id', 'invitee_id', 'game', 'mode', 'status', 'board_game_id', 'tournament_match_id', 'expires_at'])]
class BoardInvite extends Model
{
    protected function casts(): array
    {
        return [
            'status' => BoardInviteStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    /**
     * The game the accepted invite started.
     *
     * @return BelongsTo<BoardGame, $this>
     */
    public function boardGame(): BelongsTo
    {
        return $this->belongsTo(BoardGame::class);
    }

    /**
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function tournamentMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === BoardInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
