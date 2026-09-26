<?php

namespace App\Models;

use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pubkey an admin unlocked to create tournaments (plan, P8 decision 3 of
 * the user: admins and organizers an admin unlocks). An organizer sees and
 * edits the tournaments they created, nothing else of the admin area.
 *
 * @property int $id
 * @property string $pubkey
 * @property string|null $added_by_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'added_by_pubkey'])]
class TournamentOrganizer extends Model
{
    public function npub(): string
    {
        return NostrKeys::hexToNpub($this->pubkey);
    }
}
