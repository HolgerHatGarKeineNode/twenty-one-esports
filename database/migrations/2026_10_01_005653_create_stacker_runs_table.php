<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill runs (plan "Blockfill", P2): one row per issued run of our own
     * stacking game, from the one-time token to the verifier's verdict
     * (App\Support\Stacker\StackerRuns).
     *
     * - token_hash: sha256 of the one-time token; the token itself is shown
     *   once and never stored. seed: the run's 128-bit seed, unique.
     * - engine: the frozen engine version the run was issued on.
     * - issued_at / started_at / submitted_at with milliseconds: the
     *   wall-clock bracket against slow motion is measured on them.
     * - ticks / state_hash: what the player claims; status `verified` says the
     *   verifier replayed exactly that. settings: DAS/ARR/SDF from the replay.
     * - replay: the base64url input log (at most 64 KB). reason: why a run was
     *   rejected or left pending. flags: cheat hints for the admin review (P5).
     */
    public function up(): void
    {
        Schema::create('stacker_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('seed', 32)->unique();
            $table->string('engine', 16);
            $table->string('status', 16)->default('issued');
            $table->json('settings')->nullable();
            $table->timestamp('issued_at', 3);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('submitted_at', 3)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('ticks')->nullable();
            $table->string('state_hash', 8)->nullable();
            $table->mediumText('replay')->nullable();
            $table->string('reason', 32)->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'ticks']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stacker_runs');
    }
};
