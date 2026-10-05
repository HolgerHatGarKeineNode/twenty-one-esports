<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chess team matches (plan "Schach Rapid und Clan", P4; NIP rev. 9.22,
 * "Chess team matches"): a team match is a `series_matches` row between two
 * `chess/rapid` lineups.
 *
 * - `boards`: 2 or 3 for a team match, null for every series (Rocket League,
 *   EA Sports FC, Age of Empires II), so every existing row stays a series.
 * - `lineup_locked_at`: when the league froze both board orders, 30 minutes
 *   before the start (App\Support\Chess\ChessTeamMatches::lock()).
 *
 * Its own file, apart from `series_match_boards` and `chess_games`, so each
 * table is locked only for its own statement (database review 2026-10-05).
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

        Schema::table('series_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('boards')->nullable();
            $table->timestamp('lineup_locked_at')->nullable();
        });
    }

    /**
     * Refused while a team match exists: without `boards` it would read as a
     * best-of-1 series and run the report flow it never had.
     */
    public function down(): void
    {
        $teamMatches = DB::table('series_matches')->whereNotNull('boards')->count();

        if ($teamMatches > 0) {
            throw new RuntimeException("Cannot roll back: {$teamMatches} chess team match(es) exist. Without `boards` they would turn into best-of-1 series; decide about these rows first.");
        }

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn(['boards', 'lineup_locked_at']);
        });
    }
};
