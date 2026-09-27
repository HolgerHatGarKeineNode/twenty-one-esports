<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic casual cups (P25, App\Support\Tournaments\CasualCups).
 *
 * tournaments.cup_series: the game of the cup series ("<Game> Casual Cup
 * #n"); null for every other tournament. cup_number: its number, unique per
 * series; a called-off cup gives its number back (set to null), so the
 * next cup takes it again and the series has no gaps. cup_open_series: the
 * series while the cup is open (sign-up, draw, running), null once it has
 * ended; unique, so two scheduler runs can never open two cups of one game
 * (SQLite has no row locks, the index is the guard). cup_extended_at: when
 * sign-up was extended (once). cup_ended_at: when the cup finished or was
 * called off; the next one opens a gap after it.
 *
 * tournament_rounds.window_ends_at: a cup round's deadline, set when the
 * round opens; what is not played by then is decided by the league.
 *
 * chess_invites.tournament_match_id: a "Play your cup match" invite, only
 * to the opponent of that match; accepting it starts the match's game.
 *
 * Additive: existing rows keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('cup_series', 32)->nullable();
            $table->unsignedInteger('cup_number')->nullable();
            $table->string('cup_open_series', 32)->nullable()->unique();
            $table->timestamp('cup_extended_at')->nullable();
            $table->timestamp('cup_ended_at')->nullable();
            $table->unique(['cup_series', 'cup_number']);
        });

        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->timestamp('window_ends_at')->nullable();
        });

        Schema::table('chess_invites', function (Blueprint $table) {
            $table->foreignId('tournament_match_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chess_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_match_id');
        });

        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->dropColumn('window_ends_at');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropUnique(['cup_series', 'cup_number']);
            $table->dropUnique(['cup_open_series']);
            $table->dropColumn(['cup_series', 'cup_number', 'cup_open_series', 'cup_extended_at', 'cup_ended_at']);
        });
    }
};
