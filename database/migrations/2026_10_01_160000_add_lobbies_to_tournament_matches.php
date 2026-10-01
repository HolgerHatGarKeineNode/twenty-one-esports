<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lobby tournaments (plan "AoE2 und Trackmania", P10,
 * App\Support\Tournaments\Lobbies):
 *
 * - `lobby`: the lobby's settings as fixed at the draw (players, map size,
 *   victory …), its league-made name and the players' report deadline;
 * - `lobby_password`: the lobby's password, encrypted at rest and shown only
 *   to the lobby's players and the directors;
 * - `lobby_report`: the places a player reported with the end-screen
 *   screenshot, waiting for a director's confirmation.
 *
 * Additive: every other match keeps null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->json('lobby')->nullable();
            $table->text('lobby_password')->nullable();
            $table->json('lobby_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn(['lobby', 'lobby_password', 'lobby_report']);
        });
    }
};
