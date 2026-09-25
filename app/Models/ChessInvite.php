<?php

namespace App\Models;

use App\Enums\ChessInviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A blitz invite to a friend who is online (the one exception to the queue,
 * besides rematch). Accepting it starts a casual game.
 *
 * @property int $id
 * @property int $inviter_id
 * @property int $invitee_id
 * @property string $mode
 * @property ChessInviteStatus $status
 * @property int|null $chess_game_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read User $invitee
 */
#[Fillable(['inviter_id', 'invitee_id', 'mode', 'status', 'chess_game_id', 'expires_at'])]
class ChessInvite extends Model
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

    public function isOpen(): bool
    {
        return $this->status === ChessInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
