<?php

namespace App\Models;

use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin excluded a pubkey from the trust graph: raw 0, and its list
 * vouches for nobody (NIP "Reports"), from the next trust run on
 * (App\Support\SeasonChain\TrustAdmin).
 *
 * @property int $id
 * @property string $pubkey
 * @property int|null $excluded_by_id
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'excluded_by_id', 'reason'])]
class TrustExclusion extends Model
{
    public function npub(): string
    {
        return NostrKeys::hexToNpub($this->pubkey);
    }
}
