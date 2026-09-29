<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The board games join the league (plan "Mühle und Dame", P5), next to
     * chess and on their own tables, so chess keeps its own untouched.
     *
     * - board_queue_entries: a player searching a game of one board game
     *   (one row per player, as `chess_queue_entries`); `rating` is the
     *   casual rating of that game the pairing range is centred on.
     * - board_invites: an invite to one player for one board game (as
     *   `chess_invites`); `tournament_match_id` makes it a "Play your cup
     *   match" invite, whose accept starts that match's game.
     * - board_games.tournament_match_id / tournament_game: the game of a
     *   tournament match and its number within it, as on `chess_games`; the
     *   unique pair lets two concurrent starts create one game only;
     *   first_move_seconds is its first-move window (the tournament's
     *   check-in), pinned at the start as on `chess_games`.
     *
     * Additive: existing board games keep null.
     */
    public function up(): void
    {
        Schema::create('board_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->unsignedSmallInteger('rating');
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->index(['game', 'mode', 'joined_at']);
        });

        Schema::create('board_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('status', 16)->default('pending');
            $table->foreignId('board_game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tournament_match_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
            $table->index(['inviter_id', 'status']);
        });

        Schema::table('board_games', function (Blueprint $table) {
            $table->foreignId('tournament_match_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tournament_game')->nullable();
            $table->unsignedInteger('first_move_seconds')->nullable();
            $table->unique(['tournament_match_id', 'tournament_game']);
        });
    }

    public function down(): void
    {
        Schema::table('board_games', function (Blueprint $table) {
            $table->dropUnique(['tournament_match_id', 'tournament_game']);
            $table->dropConstrainedForeignId('tournament_match_id');
            $table->dropColumn(['tournament_game', 'first_move_seconds']);
        });

        Schema::dropIfExists('board_invites');
        Schema::dropIfExists('board_queue_entries');
    }
};
