<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correspondence board games (plan "Mühle und Dame", P8): nine men's
     * morris and checkers one move a day, as daily chess, on the board game
     * tables so chess keeps its own untouched.
     *
     * - board_challenges: one player challenges another to a correspondence
     *   game of one board game (as `chess_challenges`); `rated` asks for a
     *   rated game, which the accept pins the trust gate for (RatedBoard).
     * - board_games.reminded_ply: the ply whose deadline reminder went out,
     *   so each turn gets at most one reminder (as on `chess_games`).
     *
     * Additive: existing board games keep null.
     */
    public function up(): void
    {
        Schema::create('board_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('challenged_id')->constrained('users')->cascadeOnDelete();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('color', 8);
            $table->boolean('rated')->default(false);
            $table->string('message', 140)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('board_game_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['challenged_id', 'status']);
            $table->index(['challenger_id', 'status']);
        });

        Schema::table('board_games', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminded_ply')->nullable();
            $table->index(['mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('board_games', function (Blueprint $table) {
            $table->dropIndex(['mode', 'status']);
            $table->dropColumn('reminded_ply');
        });

        Schema::dropIfExists('board_challenges');
    }
};
