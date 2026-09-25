<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A league match number (NIP tag `match`), reserved for one author before the
 * challenge is signed. `id` is the number. A reservation that is never used
 * stays a gap; a gap is not a missing event (NIP "Challenge").
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'used_at'])]
class MatchNumber extends Model
{
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
