<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The answer of one relay to one published event: the `OK` flag and message
 * (NIP-01), or null plus an error when the relay never answered.
 *
 * @property int $id
 * @property int $nostr_event_id
 * @property string $relay
 * @property bool|null $accepted
 * @property string|null $message
 * @property Carbon $attempted_at
 */
#[Fillable(['nostr_event_id', 'relay', 'accepted', 'message', 'attempted_at'])]
class RelayDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }
}
