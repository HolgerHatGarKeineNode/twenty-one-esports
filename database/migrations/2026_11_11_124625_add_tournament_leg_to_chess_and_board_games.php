<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leg a game plays in a combined tournament's sequence (FormatOptions::$games, plan "combined tournament
 * sequence", P2): 1..N for the configured games, N+1… for the deciders, kept by a restart after a missed first move.
 * Ids cannot give the order, as the legs alternate between the chess and the board game tables. Null for every game
 * of a tournament of one game, and for every game outside a tournament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->unsignedSmallInteger('tournament_leg')->nullable();
        });

        Schema::table('board_games', function (Blueprint $table) {
            $table->unsignedSmallInteger('tournament_leg')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('board_games', function (Blueprint $table) {
            $table->dropColumn('tournament_leg');
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropColumn('tournament_leg');
        });
    }
};
