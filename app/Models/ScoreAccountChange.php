<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One admin decision about a game account claim of a score game (plan "AoE2
 * und Trackmania", P4; App\Support\Scores\ScoreAccounts): `confirm`,
 * `reassign` or `revoke`, with the reason and the runs it moved. Append-only,
 * like the director log. Admin-only: the account id is private.
 *
 * @property int $id
 * @property string $game
 * @property string $account_id
 * @property string $action
 * @property int|null $from_user_id
 * @property int|null $to_user_id
 * @property int|null $admin_id
 * @property string $reason
 * @property int $runs_moved
 * @property Carbon|null $created_at
 * @property-read User|null $fromUser
 * @property-read User|null $toUser
 * @property-read User|null $admin
 */
#[Fillable(['game', 'account_id', 'action', 'from_user_id', 'to_user_id', 'admin_id', 'reason', 'runs_moved', 'created_at'])]
#[Hidden(['account_id'])]
class ScoreAccountChange extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('The account log is append-only.'));
        static::deleting(fn (): never => throw new LogicException('The account log is append-only.'));
    }

    protected function casts(): array
    {
        return ['runs_moved' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
