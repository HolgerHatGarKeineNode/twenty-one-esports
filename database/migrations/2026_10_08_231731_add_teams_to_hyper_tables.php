<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hyperbitcoinization teams (plan "Hyperbitcoinization", P4): a lobby table may be a clan table, two
     * sides seated alternately (seat 0, 2, 4 = side 0; 1, 3, 5 = side 1). `team_clans` names each side's clan,
     * [side 0, side 1], null while a side has no player yet (or only bots); the match keeps the same pair, so
     * its end screen and the clans' pride read who played for whom even after a player changed clans. A
     * match seat's side is `hyper_seats.team` (there since P2).
     */
    public function up(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->json('team_clans')->nullable()->after('round_limit');
        });

        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->json('team_clans')->nullable()->after('round_limit');
        });
    }

    public function down(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->dropColumn('team_clans');
        });

        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->dropColumn('team_clans');
        });
    }
};
