<?php

namespace App\Models;

use App\Support\FairPlay\ReceivedZaps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A zap a player received, read from the relays for the admin's soft marks
 * ({@see ReceivedZaps}).
 *
 * @property int $id
 * @property string $receipt_id
 * @property string $recipient_pubkey
 * @property string $zapper_pubkey the signer of the zap request inside the receipt
 * @property int $sats
 * @property Carbon $zapped_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['receipt_id', 'recipient_pubkey', 'zapper_pubkey', 'sats', 'zapped_at'])]
class ReceivedZap extends Model
{
    protected function casts(): array
    {
        return [
            'sats' => 'integer',
            'zapped_at' => 'datetime',
        ];
    }
}
