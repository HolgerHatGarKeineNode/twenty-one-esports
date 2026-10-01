<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A game account id an admin confirmed as one player's (plan "AoE2 und
 * Trackmania", P4; App\Support\Scores\ScoreAccounts). Private: never shown
 * outside the admin review, never published.
 *
 * @property int $id
 * @property string $game
 * @property string $account_id
 * @property int $user_id
 * @property int|null $confirmed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['game', 'account_id', 'user_id', 'confirmed_by_id'])]
#[Hidden(['account_id'])]
class ScoreAccountClaim extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
