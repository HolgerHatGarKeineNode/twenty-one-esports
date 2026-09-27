<?php

namespace App\Models;

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A direct casual 1v1 invite to a player who is looking to play (P23,
 * App\Support\Series\CasualInvites). `platform` and `crossplay` are the
 * inviter's; the invitee gives theirs when accepting. The states are the
 * blitz invite's (pending, accepted, declined, withdrawn); an unanswered
 * invite is simply past `expires_at`.
 *
 * @property int $id
 * @property int $inviter_id
 * @property int $invitee_id
 * @property string $game
 * @property string $mode
 * @property Platform $platform
 * @property bool $crossplay
 * @property ChessInviteStatus $status
 * @property int|null $series_match_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read User $invitee
 * @property-read SeriesMatch|null $match
 */
#[Fillable(['inviter_id', 'invitee_id', 'game', 'mode', 'platform', 'crossplay', 'status', 'series_match_id', 'expires_at'])]
class SeriesInvite extends Model
{
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'crossplay' => 'boolean',
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

    /**
     * The casual match the accepted invite started.
     *
     * @return BelongsTo<SeriesMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(SeriesMatch::class, 'series_match_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ChessInviteStatus::Pending && $this->expires_at->isFuture();
    }
}
