<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A player's newest published trust assertion (`30382`): what the trust
 * gate reads and pins at an accept (App\Support\SeasonChain\AnchoredTrustFacts).
 *
 * @property int $id
 * @property string $pubkey
 * @property int $rank 0-100
 * @property float $raw
 * @property string|null $anchor the anchor with the largest share
 * @property int|null $anchor_share floor(100 * share)
 * @property string $anchor_list_event_id
 * @property int $trust_run_id the run that published this version
 * @property int $nostr_event_id
 * @property string $event_id the assertion id a `gate` row names
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'rank', 'raw', 'anchor', 'anchor_share', 'anchor_list_event_id', 'trust_run_id', 'nostr_event_id', 'event_id'])]
class TrustRank extends Model
{
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'raw' => 'float',
            'anchor_share' => 'integer',
        ];
    }
}
