<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The players each clan names for one match of a Hyperbitcoinization clan
 * bracket (plan "Hyperbitcoinization", P5b, App\Support\Hyper\HyperTournamentTeams):
 * since when the match waits for them, and per slot who plays, who named
 * them and when. Additive: null for every other match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->json('lineups')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('lineups');
        });
    }
};
