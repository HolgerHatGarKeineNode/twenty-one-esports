<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "Submit final score" of a captain (NIP 2152) and the other side's
 * answer (2153): accepted, or a problem reported with a public reason.
 *
 * @property int $id
 * @property int $series_match_id
 * @property int|null $user_id
 * @property string $side
 * @property list<array{winner: string, challenger: int|null, challenged: int|null}> $games
 * @property list<array{user_id: int, pubkey: string, name: string, side: string, role: string}> $roster
 * @property ReportStatus $status
 * @property int|null $event_id
 * @property int|null $responded_by_id
 * @property string|null $response_reason
 * @property int|null $response_event_id
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SeriesMatch $seriesMatch
 * @property-read User|null $user
 * @property-read User|null $respondedBy
 * @property-read NostrEvent|null $event
 * @property-read NostrEvent|null $responseEvent
 */
#[Fillable(['series_match_id', 'user_id', 'side', 'games', 'roster', 'status', 'event_id', 'responded_by_id', 'response_reason', 'response_event_id', 'responded_at'])]
class SeriesReport extends Model
{
    protected function casts(): array
    {
        return [
            'games' => 'array',
            'roster' => 'array',
            'status' => ReportStatus::class,
            'responded_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function responseEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'response_event_id');
    }

    /**
     * @return array{challenger: int, challenged: int}
     */
    public function score(): array
    {
        return SeriesMatch::seriesScore($this->games);
    }
}
