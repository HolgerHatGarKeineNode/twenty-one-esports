<?php

namespace App\Models;

use App\Support\FairPlay\ListedProfiles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The kind-0 profile of an account a Verein member lists on the league
 * opponent list, as the league read it from the relays ({@see ListedProfiles}).
 * Compared by the impersonation check while the account never logged in.
 *
 * @property int $id
 * @property string $pubkey
 * @property string|null $name
 * @property string|null $name_key the name as it reads (Impersonation::nameKey()), null when too short to compare
 * @property string|null $picture
 * @property Carbon|null $profile_event_at the `created_at` of the stored profile event
 * @property Carbon $first_seen_at when the league first archived a member's list naming the key
 * @property Carbon|null $fetched_at the last read that brought a profile
 * @property Carbon|null $checked_at the last read at all
 * @property Carbon|null $unlisted_at since when no member's newest list names the key; null = listed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'name', 'name_key', 'picture', 'profile_event_at', 'first_seen_at', 'fetched_at', 'checked_at', 'unlisted_at'])]
class ListedProfile extends Model
{
    protected function casts(): array
    {
        return [
            'profile_event_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'fetched_at' => 'datetime',
            'checked_at' => 'datetime',
            'unlisted_at' => 'datetime',
        ];
    }
}
