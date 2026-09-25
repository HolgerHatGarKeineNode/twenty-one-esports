<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live chess (P5a): games, their moves, the blitz queue and friend invites.
     *
     * Clock values are integer milliseconds and moments on the clock are Unix
     * milliseconds (`*_ms`), so the server clock is exact on every driver;
     * SQLite has no sub-second timestamp type. `deadline_ms` is the moment the
     * side to move flags (or, before both first moves, the game aborts), kept
     * as a column so the flag sweep is one indexed query. `start_fen` is null
     * for the normal start position.
     */
    public function up(): void
    {
        Schema::create('chess_games', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 32);
            $table->boolean('rated')->default(false);
            $table->foreignId('white_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('black_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('result', 8)->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->string('start_fen', 100)->nullable();
            $table->string('fen', 100);
            $table->unsignedSmallInteger('ply')->default(0);
            $table->unsignedInteger('initial_ms');
            $table->unsignedInteger('increment_ms');
            $table->unsignedInteger('white_ms');
            $table->unsignedInteger('black_ms');
            $table->unsignedBigInteger('turn_started_ms');
            $table->unsignedBigInteger('deadline_ms')->nullable();
            $table->string('draw_offer', 1)->nullable();
            $table->string('rematch_offer', 1)->nullable();
            $table->foreignId('rematch_of_id')->nullable()->constrained('chess_games')->nullOnDelete();
            $table->foreignId('rematch_id')->nullable()->constrained('chess_games')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_ms']);
        });

        Schema::create('chess_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chess_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ply');
            $table->string('uci', 5);
            $table->string('san', 10);
            $table->string('fen', 100);
            $table->unsignedInteger('spent_ms');
            $table->unsignedInteger('clock_ms');
            $table->timestamp('created_at')->nullable();

            $table->unique(['chess_game_id', 'ply']);
        });

        Schema::create('chess_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 32);
            $table->boolean('rated')->default(false);
            $table->unsignedSmallInteger('rating');
            $table->timestamp('joined_at');
            $table->timestamps();
        });

        Schema::create('chess_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->string('status', 16)->default('pending');
            $table->foreignId('chess_game_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
            $table->index(['inviter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chess_invites');
        Schema::dropIfExists('chess_queue_entries');
        Schema::dropIfExists('chess_moves');
        Schema::dropIfExists('chess_games');
    }
};
