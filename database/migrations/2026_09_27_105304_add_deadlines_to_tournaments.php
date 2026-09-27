<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournament deadlines per tournament (P18, slice 2).
 *
 * tournaments.checkin_minutes / noshow_minutes / report_hours /
 * response_minutes: the organizer's own deadlines; null = the league default
 * (config `esports.tournaments`, App\Support\Tournaments\TournamentDeadlines).
 *
 * series_matches.deadlines: the tournament's series deadlines, pinned when
 * the series is paired, so an edit after the start only reaches matches
 * paired later. series_matches.overdue_at: when the league moved a series
 * nobody reported in time to the admin queue (set once).
 *
 * chess_games.first_move_seconds: the first-move window of a tournament
 * game, pinned at its start for the same reason.
 *
 * Additive: existing rows keep null and the config defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedSmallInteger('checkin_minutes')->nullable();
            $table->unsignedSmallInteger('noshow_minutes')->nullable();
            $table->unsignedSmallInteger('report_hours')->nullable();
            $table->unsignedSmallInteger('response_minutes')->nullable();
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->json('deadlines')->nullable();
            $table->timestamp('overdue_at')->nullable();
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->unsignedInteger('first_move_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropColumn('first_move_seconds');
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn(['deadlines', 'overdue_at']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['checkin_minutes', 'noshow_minutes', 'report_hours', 'response_minutes']);
        });
    }
};
