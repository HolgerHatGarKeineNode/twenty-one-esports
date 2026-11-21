<?php

namespace App\Models;

use App\Support\FairPlay\ReceivedZaps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When a player's received zaps were last read from the relays
 * ({@see ReceivedZaps}), and how many relays answered.
 *
 * @property int $id
 * @property string $pubkey
 * @property Carbon $read_at
 * @property int $relays_answered
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'read_at', 'relays_answered'])]
class ZapRead extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'relays_answered' => 'integer',
        ];
    }
}
