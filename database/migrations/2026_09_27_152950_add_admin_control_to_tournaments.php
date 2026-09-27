<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control over a running tournament (P18, slice 4; TournamentControl):
 *
 * - `tournaments.paused_at`: set while the tournament is paused; the tick
 *   applies no deadlines and no new match starts until it is resumed.
 * - `tournament_participants.disqualified_*`: who disqualified the entry,
 *   when and why; its remaining matches are forfeited.
 * - `tournament_matches.held`: a played result set aside because a result
 *   it depended on was corrected (the old result, why, who, when); the
 *   match waits for a decision. `replaced_through`: the id of the last
 *   series or chess game of the match that no longer counts (voided or
 *   superseded by the league); a newer one is played.
 * - `tournament_rounds.restarts`: how often the round was restarted, the
 *   guard against a double click restarting it twice.
 *
 * Additive: existing rows keep null or 0 and behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('disqualified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disqualification_reason', 500)->nullable();
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->json('held')->nullable();
            $table->unsignedBigInteger('replaced_through')->nullable();
        });

        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->unsignedInteger('restarts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->dropColumn('restarts');
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn(['held', 'replaced_through']);
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disqualified_by_id');
            $table->dropColumn(['disqualified_at', 'disqualification_reason']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
