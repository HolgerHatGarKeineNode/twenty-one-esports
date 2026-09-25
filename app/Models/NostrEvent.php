<?php

namespace App\Models;

use App\Support\Nostr\SignedEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A signed event the league accepted, stored exactly as signed (`raw`), so
 * it can be published and republished without re-serialising.
 *
 * @property int $id
 * @property string $event_id
 * @property string $pubkey
 * @property int $kind
 * @property string|null $d
 * @property int $signed_at
 * @property string $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'pubkey', 'kind', 'd', 'signed_at', 'raw'])]
class NostrEvent extends Model
{
    public static function fromSigned(SignedEvent $event): self
    {
        return self::query()->create([
            'event_id' => $event->id,
            'pubkey' => $event->pubkey,
            'kind' => $event->kind,
            'd' => $event->tag('d'),
            'signed_at' => $event->createdAt,
            'raw' => $event->toJson(),
        ]);
    }

    /**
     * @return HasMany<RelayDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(RelayDelivery::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return (array) json_decode($this->raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
