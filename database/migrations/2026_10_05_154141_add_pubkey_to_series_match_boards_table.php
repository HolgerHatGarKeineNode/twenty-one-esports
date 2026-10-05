<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * series_match_boards.pubkey: the named player's pubkey, written when the
 * captain names the player and again at the lineup lock
 * (App\Support\Chess\ChessTeamMatches). `user_id` is nulled when an account
 * is deleted; a board that never started for that reason is still attested
 * (forfeit, or void when both accounts are gone) and names its players by
 * this frozen key (NIP rev. 9.22, "League Attestation", "Chess"), so the
 * board attestations still sum to the league's team score.
 *
 * Existing rows are filled from their user; a row whose account is gone
 * already stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres (prod): wait at most 5 s for the table lock, then fail the deploy instead of queueing every
        // read of the table behind it (as the three team match files, database review 2026-10-05).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('series_match_boards', function (Blueprint $table) {
            $table->string('pubkey', 64)->nullable();
        });

        DB::table('series_match_boards')->whereNull('pubkey')->whereNotNull('user_id')->update([
            'pubkey' => DB::table('users')->select('pubkey')->whereColumn('users.id', 'series_match_boards.user_id')->limit(1),
        ]);
    }

    /**
     * Refused while a lineup is named: a board whose account is deleted would
     * lose the only key it can still be attested with.
     */
    public function down(): void
    {
        $rows = DB::table('series_match_boards')->count();

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: {$rows} team match lineup row(s) exist. Dropping `pubkey` would lose the key a board of a deleted account is attested with; decide about these rows first.");
        }

        Schema::table('series_match_boards', function (Blueprint $table) {
            $table->dropColumn('pubkey');
        });
    }
};
