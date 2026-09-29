<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The board game core next to chess (plan "Mühle und Dame", P2): games of
     * the board games other than chess and their moves, in their own tables,
     * so chess keeps its own untouched.
     *
     * `game` is the registry slug (nine-mens-morris, checkers); `position` is
     * the position as that game's rules serialize it (App\Support\Board\BoardRules),
     * `turn` the side to move it names, stored so the clock needs no rules.
     * Clock values are integer milliseconds and moments on the clock Unix
     * milliseconds (`*_ms`), as in chess: exact on every driver. `deadline_ms`
     * is the moment the side to move flags (before both first moves: the game
     * aborts), a column so the flag sweep is one indexed query. `ply` is the
     * number of moves played; a move names the ply it expects to make, and the
     * unique (game, ply) index refuses a second move for the same ply.
     */
    public function up(): void
    {
        Schema::create('board_games', function (Blueprint $table) {
            $table->id();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->foreignId('white_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('black_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->string('result', 8)->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->text('position');
            $table->string('turn', 1);
            $table->unsignedSmallInteger('ply')->default(0);
            $table->unsignedInteger('initial_ms');
            $table->unsignedInteger('increment_ms');
            $table->unsignedInteger('white_ms');
            $table->unsignedInteger('black_ms');
            $table->unsignedBigInteger('turn_started_ms');
            $table->unsignedBigInteger('deadline_ms')->nullable();
            $table->string('draw_offer', 1)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_ms']);
            $table->index(['white_id', 'status']);
            $table->index(['black_id', 'status']);
        });

        Schema::create('board_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ply');
            $table->string('move', 64);
            $table->string('notation', 64);
            $table->text('position');
            $table->unsignedInteger('spent_ms');
            $table->unsignedInteger('clock_ms');
            $table->timestamp('created_at')->nullable();

            $table->unique(['board_game_id', 'ply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_moves');
        Schema::dropIfExists('board_games');
    }
};
