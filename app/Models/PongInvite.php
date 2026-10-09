<?php

namespace App\Models;

use App\Enums\BoardInviteStatus;
use Database\Factories\PongInviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invite to one player for a live Proof of Pong match (plan "Proof of Pong", P2), as BoardInvite for the board
 * games, with the same states (BoardInviteStatus). Accepting it starts the match (`pong_match_id`).
 *
 * @property int $id
 * @property int $inviter_id
 * @property int $invitee_id
 * @property BoardInviteStatus $status
 * @property int|null $pong_match_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read User $invitee
 * @property-read PongMatch|null $pongMatch
 */
#[Fillable(['inviter_id', 'invitee_id', 'status', 'pong_match_id', 'expires_at'])]
class PongInvite extends Model
{
    /** @use HasFactory<PongInviteFactory> */
    use HasFactory;

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
     * @return BelongsTo<PongMatch, $this>
     */
    public function pongMatch(): BelongsTo
    {
        return $this->belongsTo(PongMatch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === BoardInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
