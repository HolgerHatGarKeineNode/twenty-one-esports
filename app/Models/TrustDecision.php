<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One admin trust decision or its undo, append-only
 * (App\Support\SeasonChain\TrustAdmin): a row is written once and never
 * changed or deleted.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_pubkey
 * @property 'dismiss'|'restore'|'exclude'|'lift' $action
 * @property string $target a report id (dismiss, restore) or a pubkey (exclude, lift)
 * @property string $reason
 * @property Carbon|null $created_at
 */
#[Fillable(['actor_id', 'actor_pubkey', 'action', 'target', 'reason'])]
class TrustDecision extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Trust decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Trust decisions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
