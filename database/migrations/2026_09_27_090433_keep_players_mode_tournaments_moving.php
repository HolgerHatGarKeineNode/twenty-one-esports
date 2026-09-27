<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Players-mode tournaments that never hang (P18, slice 1).
 *
 * chess_games.white_seen_at / black_seen_at: when each player of a
 * tournament game first opened its board. A game whose first move was
 * missed is a no-show of the side to move; before any move, Black counts as
 * missing too if they never opened the board (both sides missed: restart).
 *
 * series_matches.tournament_attempt: an admin may void a tournament series,
 * and the match is then played again as a new series. The unique link
 * becomes (tournament_match_id, tournament_attempt): still one series per
 * attempt, so two concurrent starts cannot create a second one. The new
 * index is created before the old one is dropped, so the foreign key is
 * covered throughout (MySQL). Additive: existing series are attempt 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->timestamp('white_seen_at')->nullable();
            $table->timestamp('black_seen_at')->nullable();
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->unsignedSmallInteger('tournament_attempt')->default(1);
            $table->unique(['tournament_match_id', 'tournament_attempt']);
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropUnique(['tournament_match_id']);
        });
    }

    /**
     * Fails while a voided tournament match has a replay series (two rows for
     * one match); those have to be resolved by hand first.
     */
    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->unique(['tournament_match_id']);
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropUnique(['tournament_match_id', 'tournament_attempt']);
            $table->dropColumn('tournament_attempt');
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropColumn(['white_seen_at', 'black_seen_at']);
        });
    }
};
