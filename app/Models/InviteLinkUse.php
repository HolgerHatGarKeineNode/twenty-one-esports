<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One player who took an invite link, and so the referral: `inviter_id`
 * invited `user_id`. `was_new`: the account was created by the login the
 * player started on the invite. Never an input to ratings, blocks or rewards.
 *
 * @property int $id
 * @property int $invite_link_id
 * @property int $inviter_id
 * @property int $user_id
 * @property bool $was_new
 * @property int|null $chess_game_id
 * @property int|null $series_match_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InviteLink $link
 * @property-read User $user
 * @property-read User $inviter
 * @property-read ChessGame|null $game
 * @property-read SeriesMatch|null $match
 */
#[Fillable(['invite_link_id', 'inviter_id', 'user_id', 'was_new', 'chess_game_id', 'series_match_id'])]
class InviteLinkUse extends Model
{
    protected function casts(): array
    {
        return ['was_new' => 'boolean'];
    }

    /**
     * @return BelongsTo<InviteLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(InviteLink::class, 'invite_link_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return BelongsTo<ChessGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(ChessGame::class, 'chess_game_id');
    }

    /**
     * @return BelongsTo<SeriesMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(SeriesMatch::class, 'series_match_id');
    }
}
