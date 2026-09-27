<?php

use App\Support\Prizes\PrizePool;
use App\Support\Wallet\NwcConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A keyed fingerprint of the wallet behind a tournament's pot (security gate
 * on 55ef30e, F-A): one wallet must never back two open pots, because a pot
 * is the whole balance of its wallet and the first approval would pay out
 * the other pot's sats. HMAC-SHA256 of the wallet's pubkey under app.key,
 * never the connection string itself; indexed for the check in
 * {@see PrizePool::configurePot()}.
 *
 * Existing pots are backfilled from their encrypted connection string. Two
 * open pots that already share a wallet are only logged: nothing is
 * rewritten under a running tournament, and the next change to either pot
 * is refused until one moves to a wallet of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('pot_wallet_hash', 64)->nullable()->index();
        });

        $rows = DB::table('tournaments')->whereNotNull('pot_nwc_uri')->get(['id', 'pot_nwc_uri', 'pool_closed_at']);
        $open = [];

        foreach ($rows as $row) {
            try {
                $pubkey = NwcConnection::fromUri(Crypt::decryptString((string) $row->pot_nwc_uri))?->walletPubkey;
            } catch (DecryptException) {
                Log::warning('Pot wallet fingerprint: the connection string of a pot could not be decrypted', ['tournament' => $row->id]);

                continue;
            }

            if ($pubkey === null) {
                continue;
            }

            $hash = PrizePool::walletFingerprint($pubkey);
            DB::table('tournaments')->where('id', $row->id)->update(['pot_wallet_hash' => $hash]);

            if ($row->pool_closed_at === null) {
                $open[$hash][] = $row->id;
            }
        }

        foreach ($open as $ids) {
            if (count($ids) > 1) {
                Log::warning('Pot wallet fingerprint: open pots share one wallet', ['tournaments' => $ids]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropIndex(['pot_wallet_hash']);
            $table->dropColumn('pot_wallet_hash');
        });
    }
};
