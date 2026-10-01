<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Score games (plan "AoE2 und Trackmania", P4): the records the league reads
 * (`score_runs`), the dedicated servers that report finishes to it
 * (`score_servers`, token stored hashed), and the course a leaderboard
 * tournament is played on. Additive only: nothing reads these while no score
 * game is registered.
 *
 * A run is one best value a source saw: nothing overwrites it, so the best
 * inside a window stays even when a later record replaces it at the source.
 * `user_id` null: a server reported an account id no player stored yet
 * (pending, never shown). `account_id` is that private game account id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('game', 64);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('score_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('game', 64);
            $table->string('mode', 64);
            $table->string('course', 64);
            $table->unsignedBigInteger('value')->nullable();
            $table->string('unit', 8);
            $table->string('source', 32);
            $table->timestamp('achieved_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('proof_url', 500)->nullable();
            $table->json('raw')->nullable();
            $table->string('account_id', 128)->nullable();
            $table->foreignId('score_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 128)->nullable();
            $table->timestamps();

            $table->index(['game', 'mode', 'course', 'achieved_at']);
            $table->index(['tournament_id', 'user_id']);
            $table->index(['source', 'verified_at', 'rejected_at']);
            $table->unique(['score_server_id', 'external_id']);
            $table->unique(['source', 'user_id', 'game', 'mode', 'course', 'achieved_at', 'value'], 'score_runs_seen_once');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('score_course', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('score_course');
        });

        Schema::dropIfExists('score_runs');
        Schema::dropIfExists('score_servers');
    }
};
