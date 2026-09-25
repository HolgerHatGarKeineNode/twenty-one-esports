<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A screenshot uploaded with a dispute (end screen, match history). Stored
 * on the private `local` disk, served only to admins, never published
 * (NIP 2153: "Evidence never goes into the event").
 *
 * @property int $id
 * @property int $series_match_id
 * @property int|null $user_id
 * @property string $path
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['series_match_id', 'user_id', 'path', 'name'])]
class DisputeEvidence extends Model
{
    protected $table = 'dispute_evidence';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
