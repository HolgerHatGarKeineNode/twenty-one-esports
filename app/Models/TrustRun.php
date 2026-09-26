<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of the trust job (App\Support\SeasonChain\TrustJob).
 *
 * @property int $id
 * @property int|null $season_id the live season when it ran
 * @property string $trust_pubkey
 * @property int $anchor_list_nostr_event_id
 * @property int $anchors
 * @property int $lists
 * @property int $ranked players with rank above 0
 * @property int $published assertions published by this run
 * @property Carbon $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NostrEvent $anchorList
 */
#[Fillable(['season_id', 'trust_pubkey', 'anchor_list_nostr_event_id', 'anchors', 'lists', 'ranked', 'published', 'computed_at'])]
class TrustRun extends Model
{
    protected function casts(): array
    {
        return [
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function anchorList(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'anchor_list_nostr_event_id');
    }
}
