<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every tournament pot is booked in the league wallet (user, 2026-10-02:
 * „bitte nicht pro Turnier Wallets fordern, warum nicht einfach in
 * existierende NWC, das landet eh alles in eine Wallet von wo aus
 * ausgezahlt werden kann"). A pot in a tournament's own wallet whose payouts
 * were not approved yet becomes a league pot, with a line in the
 * tournament's moderation log; its old connection and last balance stay in
 * their columns, unused (the pool page tells the organizer that the old
 * wallet keeps its sats until they are added to the pot). A pot whose
 * payouts were approved keeps its own wallet and finishes paying from it.
 * Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pots = DB::table('tournaments')->where('pot_source', 'wallet')->whereNull('payouts_approved_at')->get(['id']);

        foreach ($pots as $row) {
            DB::transaction(function () use ($row): void {
                DB::table('tournaments')->where('id', $row->id)->where('pot_source', 'wallet')->whereNull('payouts_approved_at')
                    ->update(['pot_source' => 'league', 'updated_at' => now()]);
                DB::table('tournament_moderation_entries')->insert([
                    'tournament_id' => $row->id,
                    'user_id' => null,
                    'user_name' => 'TWENTY ONE',
                    'action' => 'edited',
                    'subject' => 'Prize pot',
                    'reason' => 'Prize pots are now kept in the league wallet. The sats in this pot\'s own wallet stay there and no longer count: add them to the pot with "Add to the pot" on the tournament page before the payouts.',
                    'details' => json_encode(['pot_source' => ['wallet', 'league']]),
                    'created_at' => now(),
                ]);
            });
        }

        if ($pots->isNotEmpty()) {
            Log::info('Prize pots: own-wallet pots moved to the league wallet', ['tournaments' => $pots->pluck('id')->all()]);
        }
    }

    public function down(): void
    {
        // The moved pots are listed in their moderation logs; they stay league pots (their wallets were never touched).
    }
};
