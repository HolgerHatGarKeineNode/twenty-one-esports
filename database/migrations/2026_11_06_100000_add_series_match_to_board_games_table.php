<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The board games of a Blockli team match (NIP rev. 9.25), as
 * 2026_10_05_135639_add_series_match_to_chess_games_table gave them to chess:
 * `series_match_id` (the team match, restrictOnDelete as
 * `tournament_match_id`) and `board` (1..boards), with the unique
 * (series_match_id, board) as the backstop of the start. A board takes no
 * match number of its own, so `board_games.number` stays null for it.
 *
 * `white_seen_at` / `black_seen_at` as chess_games has them since
 * 2026_09_27_090433_keep_players_mode_tournaments_moving: when each player
 * first opened the board, so a Black who never opened a board that nobody
 * moved on missed the first move too (both missed: the board is void).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres (prod): wait at most 5 s for the table lock, then fail the deploy instead of queueing every
        // read of the table behind it (as the chess migration of the team matches).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('board_games', function (Blueprint $table) {
            $table->foreignId('series_match_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('board')->nullable();
            $table->timestamp('white_seen_at')->nullable();
            $table->timestamp('black_seen_at')->nullable();
            $table->unique(['series_match_id', 'board']);
        });
    }

    /**
     * Refused while a board of a team match exists: it would become a solo
     * game without a number, outside its team match.
     */
    public function down(): void
    {
        $games = DB::table('board_games')->whereNotNull('series_match_id')->count();

        if ($games > 0) {
            throw new RuntimeException("Cannot roll back: {$games} board game(s) are boards of a team match. They would lose their team match and have no match number; decide about these rows first.");
        }

        Schema::table('board_games', function (Blueprint $table) {
            $table->dropUnique(['series_match_id', 'board']);
            $table->dropConstrainedForeignId('series_match_id');
            $table->dropColumn(['board', 'white_seen_at', 'black_seen_at']);
        });
    }
};
