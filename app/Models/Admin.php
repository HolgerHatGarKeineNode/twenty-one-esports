<?php

namespace App\Models;

use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pubkey granted admin rights through the admin UI.
 *
 * @property int $id
 * @property string $pubkey
 * @property string|null $added_by_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'added_by_pubkey'])]
class Admin extends Model
{
    public function npub(): string
    {
        return NostrKeys::hexToNpub($this->pubkey);
    }
}
