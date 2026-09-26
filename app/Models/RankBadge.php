<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player's NIP-58 rank badge in one game and mode (NIP "Rank badges"): one
 * definition (`30009`, `d` = `rank/<game>/<mode>/<pubkey>`) that the badge
 * key replaces on every rank change, and one award (`8`) that never changes.
 * App\Support\Badges\RankBadges is the only writer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey
 * @property string $game
 * @property string $mode
 * @property string $d
 * @property string $badge_pubkey the badge key that signs the definition
 * @property string $tier the tier of the newest version
 * @property string $season the season that tier is from
 * @property int|null $definition_event_id the newest version
 * @property int|null $award_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read NostrEvent|null $definitionEvent
 * @property-read NostrEvent|null $awardEvent
 */
#[Fillable(['user_id', 'pubkey', 'game', 'mode', 'd', 'badge_pubkey', 'tier', 'season', 'definition_event_id', 'award_event_id'])]
class RankBadge extends Model
{
    public const DEFINITION = 30009;

    public const AWARD = 8;

    /** `30009:<badge key>:<d>`, the `a` of the award and of a profile entry. */
    public function address(): string
    {
        return self::DEFINITION.':'.$this->badge_pubkey.':'.$this->d;
    }

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
    public function definitionEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'definition_event_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function awardEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'award_event_id');
    }

    /**
     * @return HasMany<RankBadgeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RankBadgeVersion::class);
    }
}
