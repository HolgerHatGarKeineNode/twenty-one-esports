<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last board game of a combined tournament's pairing that no longer counts (plan "combined tournament sequence",
 * P4): a sequence plays chess and board games, two tables whose ids run independently, so one `replaced_through`
 * cannot cover both. Written only for sequence pairings (TournamentControl), beside `replaced_through` for their chess
 * games; null everywhere else, where `replaced_through` covers the board games as before
 * (TournamentMatch::boardReplacedThrough()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('replaced_board_through')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('replaced_board_through');
        });
    }
};
