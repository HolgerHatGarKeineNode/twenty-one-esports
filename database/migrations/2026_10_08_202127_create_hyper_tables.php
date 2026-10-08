<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hyperbitcoinization matches (plan "Hyperbitcoinization", P2), in their own tables: the board game
     * core knows two sides, a match here has 2 to 6 seats, bots and later teams.
     *
     * `ulid` is the public id in URLs and channel names. `state` is the rules core's state
     * (App\Support\Hyper\HyperGame::toArray()) after the last action, `ply` the number of actions played;
     * every action is a row of `hyper_actions` with the events it caused, so seed + actions replay the
     * match. `seed` is a 32-bit unsigned value, hence a signed big integer (PostgreSQL has no unsigned
     * types). Moments on the clock are Unix milliseconds (`*_ms`), exact on every driver; `deadline_ms`
     * is when the seat to move runs out of time, a column so the sweep is one indexed query.
     */
    public function up(): void
    {
        Schema::create('hyper_matches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->bigInteger('seed');
            $table->unsignedSmallInteger('round_limit')->default(0);
            $table->boolean('rated')->default(false);
            $table->json('state');
            $table->unsignedInteger('ply')->default(0);
            $table->unsignedTinyInteger('current_seat')->nullable();
            $table->unsignedBigInteger('turn_started_ms')->nullable();
            $table->unsignedBigInteger('deadline_ms')->nullable();
            $table->unsignedTinyInteger('winner_seat')->nullable();
            $table->string('end_reason', 16)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_ms']);
        });

        Schema::create('hyper_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hyper_match_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('faction', 16);
            $table->boolean('bot')->default(false);
            $table->unsignedTinyInteger('team')->nullable();
            $table->unsignedTinyInteger('place')->nullable();
            $table->double('loot')->default(0);
            $table->unsignedTinyInteger('timeouts')->default(0);
            $table->string('takeover', 16)->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['hyper_match_id', 'seat']);
            $table->index(['user_id', 'hyper_match_id']);
        });

        Schema::create('hyper_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hyper_match_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ply');
            $table->unsignedTinyInteger('seat');
            $table->string('source', 8);
            $table->json('action');
            $table->json('events');
            $table->timestamp('created_at')->nullable();

            $table->unique(['hyper_match_id', 'ply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hyper_actions');
        Schema::dropIfExists('hyper_seats');
        Schema::dropIfExists('hyper_matches');
    }
};
