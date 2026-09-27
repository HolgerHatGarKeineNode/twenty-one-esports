<?php

namespace App\Console\Commands;

use App\Models\WalletReconciliation;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The daily reconciliation (P9, plan "Wallet und Töpfe"): the wallet's
 * balance against the sum of the pots in the ledger. Every run is stored;
 * a deviation or a wallet that does not answer is logged as a warning and
 * shown to admins on the payouts page. The balance is read through the
 * receiving connection; the paying one is never used here.
 */
#[Signature('wallet:reconcile')]
#[Description('Compare the league wallet balance with the sum of the pots')]
class WalletReconcileCommand extends Command
{
    public function handle(Ledger $ledger): int
    {
        $wallet = ReceivingWallet::fromConfig();

        if ($wallet === null) {
            $this->warn('No receiving wallet connection (ESPORTS_NWC_RECEIVE_URI): nothing to reconcile.');

            return self::SUCCESS;
        }

        $book = $ledger->pots();

        try {
            $balance = $wallet->balanceSats();
        } catch (NwcError $error) {
            WalletReconciliation::query()->create(['wallet_sats' => null, 'ledger_sats' => $book, 'deviation_sats' => null, 'error' => mb_substr($error->errorCode, 0, 120), 'created_at' => now()]);
            Log::warning('Wallet reconciliation: the wallet did not answer', ['code' => $error->errorCode]);
            $this->error('The wallet did not answer: '.$error->errorCode);

            return self::FAILURE;
        }

        $run = WalletReconciliation::query()->create(['wallet_sats' => $balance, 'ledger_sats' => $book, 'deviation_sats' => $balance - $book, 'created_at' => now()]);

        if ($run->deviation_sats !== 0) {
            Log::warning('Wallet reconciliation: the wallet and the pots differ', ['wallet_sats' => $balance, 'ledger_sats' => $book]);
            $this->error("Deviation: wallet {$balance} sats, pots {$book} sats.");

            return self::FAILURE;
        }

        $this->info("Wallet and pots agree: {$book} sats.");

        return self::SUCCESS;
    }
}
