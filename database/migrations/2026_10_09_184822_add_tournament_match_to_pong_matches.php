<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof of Pong in tournaments (plan "Proof of Pong", P4): `pong_matches.tournament_match_id`, the tournament match
     * a live match plays (its winner goes back to the bracket, TournamentRunner::pongMatchFinished()).
     */
    public function up(): void
    {
        Schema::table('pong_matches', function (Blueprint $table) {
            $table->foreignId('tournament_match_id')->nullable()->after('rematch_of_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pong_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_match_id');
        });
    }
};
