<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One browser's Web Push subscription (PushSubscription.toJSON() of the Push
 * API): the push service endpoint and the browser's keys for RFC 8291
 * message encryption. A push service answering 404/410 deletes it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $public_key `keys.p256dh`, base64url, the browser's P-256 key
 * @property string $auth_token `keys.auth`, base64url, 16 bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
