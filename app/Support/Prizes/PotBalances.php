<?php

namespace App\Support\Prizes;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use Illuminate\Database\Eloquent\Collection;

/**
 * The balance of the pots held in a tournament's own NWC wallet (P9 scope
 * addition, user 2026-09-27: "ein NWC einstellen können, wo die Balance des
 * Topfs ausgelesen wird"). Read on a schedule (`wallet:read-pots`) and on
 * an admin's demand, through NIP-47 `get_balance`.
 *
 * Fail closed: a read that fails keeps the last good value and its time
 * and records why (`pot_balance_error`, a wallet error code, never text
 * from the connection string); nothing is ever estimated. A closed pot is
 * no longer read: its balance is the one the payouts were split from.
 */
final class PotBalances
{
    /**
     * Read one pot now; false when the wallet gave no balance.
     */
    public function read(Tournament $tournament): bool
    {
        if (! $tournament->hasOwnWallet() || $tournament->pool_closed_at !== null) {
            return false;
        }

        $wallet = ReceivingWallet::fromUri($tournament->pot_nwc_uri);

        if ($wallet === null) {
            $tournament->forceFill(['pot_balance_error' => 'NO_CONNECTION'])->save();

            return false;
        }

        try {
            $sats = $wallet->balanceSats();
        } catch (NwcError $error) {
            $tournament->forceFill(['pot_balance_error' => self::code($error)])->save();

            return false;
        }

        $tournament->forceFill(['pot_balance_sats' => $sats, 'pot_balance_at' => now(), 'pot_balance_error' => null])->save();

        return true;
    }

    /**
     * The pots the schedule reads: own-wallet pots not closed yet, of
     * tournaments that were not cancelled.
     *
     * @return Collection<int, Tournament>
     */
    public function due(): Collection
    {
        return Tournament::query()->where('pot_source', Tournament::POT_WALLET)->whereNull('pool_closed_at')
            ->where('status', '!=', TournamentStatus::Cancelled)->orderBy('id')->get();
    }

    /**
     * A wallet error code as stored and shown: letters, digits and `_` only.
     */
    public static function code(NwcError $error): string
    {
        return mb_substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper($error->errorCode)) ?: 'OTHER', 0, 40);
    }
}
