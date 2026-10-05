<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The board games of a chess team match: `series_match_id` (the team match,
 * restrictOnDelete as `tournament_match_id`) and `board` (1..boards). The
 * unique (series_match_id, board) is the backstop of the start: a second tick
 * or a parallel worker cannot create a board twice. A board game takes no
 * match number of its own (NIP: every board attestation carries the number
 * of the challenge), so `chess_games.number` stays null for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres (prod): wait at most 5 s for the table lock, then fail the deploy instead of queueing every
        // read of the table behind it (database review 2026-10-05). Inside the migration's own transaction.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('chess_games', function (Blueprint $table) {
            $table->foreignId('series_match_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('board')->nullable();
            $table->unique(['series_match_id', 'board']);
        });
    }

    /**
     * Refused while a board game exists: it would become a solo game without
     * a number, outside its team match.
     */
    public function down(): void
    {
        $games = DB::table('chess_games')->whereNotNull('series_match_id')->count();

        if ($games > 0) {
            throw new RuntimeException("Cannot roll back: {$games} chess game(s) are boards of a team match. They would lose their team match and have no match number; decide about these rows first.");
        }

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropUnique(['series_match_id', 'board']);
            $table->dropConstrainedForeignId('series_match_id');
            $table->dropColumn('board');
        });
    }
};
