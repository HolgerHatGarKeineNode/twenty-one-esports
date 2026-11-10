<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The start vote of a one-day tournament (DerCaddy, 2026-11-09: Blitz and
 * Rapid tournaments whose players vote on the day and time until a deadline;
 * App\Support\Tournaments\TournamentStartVote):
 *
 * - `start_options`: the two to four starts the organizer offers, UTC as
 *   `Y-m-d H:i`, earliest first; null = no vote;
 * - `start_vote_until`: when the vote closes;
 * - `start_decided_at`: when the league settled it (the start with the most
 *   entries that can make it); null while it is open;
 * - `tournament_signups.start_fits`: the offered starts this entry can make,
 *   in the same form; null = it has not voted.
 *
 * League data only: the vote is never published, as the entries are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->json('start_options')->nullable();
            $table->timestamp('start_vote_until')->nullable();
            $table->timestamp('start_decided_at')->nullable();
        });

        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->json('start_fits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->dropColumn('start_fits');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['start_options', 'start_vote_until', 'start_decided_at']);
        });
    }
};
