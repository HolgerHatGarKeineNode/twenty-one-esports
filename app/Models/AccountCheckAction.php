<?php

namespace App\Models;

use App\Support\FairPlay\AccountChecks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One quick action an admin took on an account's check view
 * ({@see AccountChecks}). Never deleted.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey
 * @property string $action `clear` | `hold` | `link` | `bar` | `exclude`
 * @property string|null $note
 * @property int|null $admin_id
 * @property string $admin_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $admin
 */
#[Fillable(['user_id', 'pubkey', 'action', 'note', 'admin_id', 'admin_pubkey'])]
class AccountCheckAction extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
