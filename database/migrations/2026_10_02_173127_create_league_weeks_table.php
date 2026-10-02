<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The admin approval of every league week (Blockfill, TMNF; user
 * 2026-10-02): the next week of a game is a draft with its settings until an
 * admin approves it, and only an approved week starts (App\Models\LeagueWeek,
 * App\Support\Scores\LeagueWeekDrafts). A new table only: the weeks that run
 * today (their tournaments) are not touched, they keep running and end as
 * they would have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_weeks', function (Blueprint $table) {
            $table->id();
            $table->string('game', 32);
            // Monday 00:00 Europe/Berlin of the week, in UTC: the planned start.
            $table->dateTime('starts_at');
            $table->json('settings');
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            // The week's leaderboard once it started; null while it is a draft or waits for its start.
            $table->foreignId('tournament_id')->nullable()->unique()->constrained('tournaments')->nullOnDelete();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            // TMNF: when the listener saw the server on the week's track (the week starts only then).
            $table->dateTime('track_ready_at')->nullable();
            // TMNF: when the admins were told the server could not be switched (once per week).
            $table->dateTime('warned_at')->nullable();
            $table->timestamps();

            $table->unique(['game', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_weeks');
    }
};
