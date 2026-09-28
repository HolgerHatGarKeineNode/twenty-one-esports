<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A confirmed false report (P41): an admin decided a dispute against the
 * captain who reported the result, so the reported result was false
 * (App\Support\Series\SeriesService::decide(), type `false_report`). Enough
 * of them in a window bar the player from rated play for a while
 * (App\Support\FairPlay\FairPlay::lockedUntil()). `created_at` is the time
 * of the decision.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey
 * @property int|null $series_match_id
 * @property int|null $series_report_id
 * @property int|null $decided_by_id
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read SeriesMatch|null $seriesMatch
 * @property-read User|null $decidedBy
 */
#[Fillable(['user_id', 'pubkey', 'series_match_id', 'series_report_id', 'decided_by_id', 'created_at'])]
class FalseReport extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SeriesMatch, $this>
     */
    public function seriesMatch(): BelongsTo
    {
        return $this->belongsTo(SeriesMatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
