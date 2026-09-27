<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One daily comparison of the wallet's balance with the sum of the pots
 * (P9, `wallet:reconcile`). `deviation_sats` = wallet minus book; null with
 * an `error` when the wallet could not be asked.
 *
 * @property int $id
 * @property int|null $wallet_sats
 * @property int $ledger_sats
 * @property int|null $deviation_sats
 * @property string|null $error
 * @property Carbon $created_at
 */
#[Fillable(['wallet_sats', 'ledger_sats', 'deviation_sats', 'error', 'created_at'])]
class WalletReconciliation extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['wallet_sats' => 'integer', 'ledger_sats' => 'integer', 'deviation_sats' => 'integer', 'created_at' => 'datetime'];
    }

    public function agrees(): bool
    {
        return $this->error === null && $this->deviation_sats === 0;
    }
}
