<?php

namespace App\Models;

use App\Support\Rating\RankTiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed version of a rank badge definition: the tier it names, the tier
 * before it (null for the first) and the rating at that moment. A rank-up
 * share card is drawn from one version, so it never changes after posting.
 *
 * @property int $id
 * @property int $rank_badge_id
 * @property string $tier
 * @property string|null $previous_tier
 * @property string $season
 * @property int $rating
 * @property int $signed_at
 * @property int|null $nostr_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RankBadge $badge
 * @property-read NostrEvent|null $nostrEvent
 */
#[Fillable(['rank_badge_id', 'tier', 'previous_tier', 'season', 'rating', 'signed_at', 'nostr_event_id'])]
class RankBadgeVersion extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'signed_at' => 'integer'];
    }

    /**
     * A step up: the first tier (the rank reveal) or a higher tier than
     * before. A step down or a new season at the same tier is a version too,
     * but nothing to share.
     */
    public function isRankUp(): bool
    {
        if ($this->previous_tier === null) {
            return true;
        }

        $order = array_keys(RankTiers::fromConfig()->ascending());

        return (int) array_search($this->tier, $order, true) > (int) array_search($this->previous_tier, $order, true);
    }

    /**
     * @return BelongsTo<RankBadge, $this>
     */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(RankBadge::class, 'rank_badge_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }
}
