<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One booking of the league's double-entry book (P9): `sats` move from one
 * account to another. A row can only move an amount from A to B, so every
 * booking balances by construction and all accounts always sum to zero.
 * Written only by App\Support\Wallet\Ledger.
 *
 * Accounts: `outside` (the world outside the wallet), `reserve`,
 * `tournament:<id>`. The wallet holds what the pots hold: minus the
 * balance of `outside`.
 *
 * @property int $id
 * @property string $from_account
 * @property string $to_account
 * @property int $sats
 * @property string $reason contribution | payout | fee | remainder | season_payout | season_payout_fee | tournament_payout | tournament_payout_fee
 * @property int|null $incoming_payment_id
 * @property int|null $tournament_payout_id
 * @property int|null $tournament_id
 * @property int|null $season_payout_id
 * @property Carbon $created_at
 */
#[Fillable(['from_account', 'to_account', 'sats', 'reason', 'incoming_payment_id', 'tournament_payout_id', 'tournament_id', 'season_payout_id', 'created_at'])]
class LedgerTransfer extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['sats' => 'integer', 'created_at' => 'datetime'];
    }
}
