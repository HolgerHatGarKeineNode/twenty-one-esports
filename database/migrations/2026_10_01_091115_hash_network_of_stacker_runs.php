<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Blockfill (security audit round 6): `stacker_runs.network` held the
 * submitter's address in plain text, and a verified run kept it for good.
 * It now holds a keyed hash (StackerRuns::networkKey()) and only while the
 * run waits for the verifier:
 *
 *  - a run still waiting (verifying, or pending with its replay) gets its
 *    network hashed, so the per-network share keeps counting it;
 *  - every other run loses it.
 *
 * Not reversible: the addresses are gone, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        $waiting = fn () => DB::table('stacker_runs')
            ->whereNotNull('network')
            ->whereIn('status', ['verifying', 'pending'])
            ->whereNotNull('replay');

        DB::table('stacker_runs')
            ->whereNotNull('network')
            ->whereNotIn('id', $waiting()->select('id'))
            ->update(['network' => null]);

        foreach ($waiting()->pluck('network', 'id') as $id => $network) {
            // a value hashed already (64 hex) stays as it is
            if (preg_match('/^[0-9a-f]{64}$/', (string) $network) !== 1) {
                DB::table('stacker_runs')->where('id', $id)
                    ->update(['network' => hash_hmac('sha256', (string) $network, (string) config('app.key'))]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
