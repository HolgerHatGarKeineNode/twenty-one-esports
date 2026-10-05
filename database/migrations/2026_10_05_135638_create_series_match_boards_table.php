<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * series_match_boards: the players a captain named for a chess team match
 * (App\Support\Chess\ChessTeamMatches), one row per player and side.
 *
 * - Before the lineup lock `board` is null: the captain's pick, hidden from
 *   the other side (NIP rev. 9.22, "Lineup lock").
 * - At the lock the league orders each side by rapid Elo and writes `board`
 *   (1..boards) with the rating it ordered by: `rating`, the pool it came
 *   from (`rated`, `casual` or `start`) and the results on that ladder (the
 *   first tiebreak), so the order stays explainable after ratings move.
 * - `user_id` is nulled when an account is deleted; the board keeps its row.
 *
 * Index (user_id, series_match_id): "is this player reserved for a locked
 * team match" is asked on every queue join, invite and game start.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres (prod): the foreign keys lock series_matches and users briefly; wait at most 5 s for that,
        // then fail the deploy instead of queueing their reads behind it (as files 1 and 3, review 2026-10-05).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('series_match_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_match_id')->constrained()->cascadeOnDelete();
            $table->string('side', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('board')->nullable();
            $table->integer('rating')->nullable();
            $table->string('rating_pool', 16)->nullable();
            $table->unsignedInteger('rating_results')->nullable();
            $table->timestamps();

            $table->unique(['series_match_id', 'user_id']);
            $table->unique(['series_match_id', 'side', 'board']);
            $table->index(['user_id', 'series_match_id']);
        });
    }

    /**
     * Refused while a lineup is named: the captains' picks and the frozen
     * board orders would be gone for good.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('series_match_boards') ? DB::table('series_match_boards')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: {$rows} team match lineup row(s) exist. Dropping them would lose the captains' picks and the frozen board orders; decide about these rows first.");
        }

        Schema::dropIfExists('series_match_boards');
    }
};
