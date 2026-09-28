<?php

namespace App\Models;

use App\Support\SeasonChain\OpponentRequests;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A player listed by someone they do not list back (P57,
 * {@see OpponentRequests}): when they were told, and whether they declined.
 * A decline hides the request and stops further notifications from that
 * requester; it is reversible and never touches the requester's list.
 *
 * @property int $id
 * @property int $user_id the listed player
 * @property string $requester_pubkey
 * @property Carbon|null $notified_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'requester_pubkey', 'notified_at', 'declined_at'])]
class OpponentRequest extends Model
{
    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'declined_at' => 'datetime'];
    }
}
