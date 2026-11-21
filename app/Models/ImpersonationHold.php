<?php

namespace App\Models;

use App\Support\FairPlay\Impersonation;
use Database\Factories\ImpersonationHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account held from new rated play because its name reads like, or its
 * picture URL equals, that of another account the league knows
 * ({@see Impersonation}), or held by an admin by hand from the account's
 * check view (`manual`, no matched account). Open until an admin decided:
 * released, linked to the matched account as the same person, or barred.
 * The row stays, so who decided what and when can always be read.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey the held account
 * @property int|null $matched_user_id
 * @property string|null $matched_pubkey the account it looks like; null for a manual hold
 * @property string $matched `name` | `picture` | `both` | `manual`
 * @property string|null $name_skeleton the held account's name as it reads, when the name matched
 * @property string|null $picture the held account's picture URL, when the picture matched
 * @property Carbon $detected_at
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_id
 * @property string|null $decided_by_pubkey
 * @property string|null $decision `released` | `linked` | `barred`
 * @property string|null $note the admin's reason or note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $matchedUser
 * @property-read User|null $decidedBy
 */
#[Fillable(['user_id', 'pubkey', 'matched_user_id', 'matched_pubkey', 'matched', 'name_skeleton', 'picture', 'detected_at', 'decided_at', 'decided_by_id', 'decided_by_pubkey', 'decision', 'note'])]
class ImpersonationHold extends Model
{
    /** @use HasFactory<ImpersonationHoldFactory> */
    use HasFactory;

    public const NAME = 'name';

    public const PICTURE = 'picture';

    public const BOTH = 'both';

    /** Held by an admin from the account's check view, for review: no matched account. */
    public const MANUAL = 'manual';

    public const RELEASED = 'released';

    public const LINKED = 'linked';

    public const BARRED = 'barred';

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The holds no admin decided yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('decided_at');
    }

    public function isOpen(): bool
    {
        return $this->decided_at === null;
    }

    public function matchesName(): bool
    {
        return in_array($this->matched, [self::NAME, self::BOTH], true);
    }

    public function matchesPicture(): bool
    {
        return in_array($this->matched, [self::PICTURE, self::BOTH], true);
    }

    public function isManual(): bool
    {
        return $this->matched === self::MANUAL;
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
    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
