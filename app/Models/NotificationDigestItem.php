<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notification waiting for the player's daily DM digest (P45,
 * App\Support\Notifications\DmDigest). Title, body and link as the instant
 * DM would have carried them; deleted when the digest goes out.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string $url
 * @property int|null $match
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'kind', 'title', 'body', 'url', 'match'])]
class NotificationDigestItem extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'match' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
