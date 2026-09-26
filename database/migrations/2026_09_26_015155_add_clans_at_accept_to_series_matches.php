<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each player's clan at the accept (P7c, NIP "League Attestation": the
     * `clan` row is the clan a rated player was an active member of at the
     * accept): pubkey => clan address, for the active players of both
     * lineups. Read by consensus rule 3 and the 2154 `clan` rows; null for
     * series accepted before this column.
     */
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->json('clans_at_accept')->nullable()->after('answered_at');
        });
    }

    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn('clans_at_accept');
        });
    }
};
