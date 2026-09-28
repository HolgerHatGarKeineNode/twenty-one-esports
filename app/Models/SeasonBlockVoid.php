<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction of the season review (NIP "Review and corrections"): block
 * `height` of the season is void, for the public `reason`. Written once by
 * App\Support\SeasonChain\SeasonSettlement::void() together with the
 * league's `void-block` label (1985); the block keeps its height and its
 * place in every counter, its reward goes to the reserve.
 *
 * @property int $id
 * @property int $season_id
 * @property int $height
 * @property int $season_attestation_id
 * @property string $reason
 * @property int|null $voided_by_id
 * @property string $voided_by_pubkey
 * @property int|null $nostr_event_id the league's 1985 label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Season $season
 * @property-read SeasonAttestation $attestation
 * @property-read User|null $voidedBy
 * @property-read NostrEvent|null $nostrEvent
 */
#[Fillable(['season_id', 'height', 'season_attestation_id', 'reason', 'voided_by_id', 'voided_by_pubkey', 'nostr_event_id'])]
class SeasonBlockVoid extends Model
{
    protected function casts(): array
    {
        return [
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<SeasonAttestation, $this>
     */
    public function attestation(): BelongsTo
    {
        return $this->belongsTo(SeasonAttestation::class, 'season_attestation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'nostr_event_id');
    }
}
