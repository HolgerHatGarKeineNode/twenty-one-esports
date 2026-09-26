<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The one award (`8`) of a quest badge (`30009`, `d` = `quest/<slug>`) to a
 * player (NIP "Rank badges", Limits). App\Support\Badges\QuestBadges writes it.
 *
 * @property int $id
 * @property string $slug
 * @property int|null $user_id
 * @property string $pubkey
 * @property int|null $award_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read NostrEvent|null $awardEvent
 */
#[Fillable(['slug', 'user_id', 'pubkey', 'award_event_id'])]
class QuestBadgeAward extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function awardEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'award_event_id');
    }
}
