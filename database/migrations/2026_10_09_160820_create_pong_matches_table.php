<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live Proof of Pong matches between two players (plan "Proof of Pong", P2), in their own table as
     * Hyperbitcoinization's: a match is two sides and a rally state, nothing a board game or a series has.
     *
     * `ulid` is the public id in URLs and channel names. `left_id` plays side 0 (left, or at the bottom on a phone
     * held upright), `right_id` side 1. `seed` is a 32-bit unsigned value, hence a signed big integer (PostgreSQL has
     * no unsigned types). `state` is the referee's state of the rally in play (App\Support\Pong\PongReferee), `log`
     * the rallies' decisions (serves, hits with the paddle's position, goals and why), so a match can be checked
     * after the fact. Moments on the server's clock are Unix milliseconds inside `state`; the columns are what the
     * league's lists read: status, score, winner, end reason, the two ratings before and after a rated match.
     */
    public function up(): void
    {
        Schema::create('pong_matches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('left_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('right_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('seed');
            $table->string('status', 16);
            $table->unsignedSmallInteger('score_left')->default(0);
            $table->unsignedSmallInteger('score_right')->default(0);
            $table->json('state');
            $table->json('log');
            $table->boolean('rated')->default(false);
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 16)->nullable();
            $table->foreignId('rematch_of_id')->nullable()->constrained('pong_matches')->nullOnDelete();
            $table->integer('left_rating_before')->nullable();
            $table->integer('left_rating_after')->nullable();
            $table->integer('right_rating_before')->nullable();
            $table->integer('right_rating_after')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'ended_at']);
            $table->index(['left_id', 'status']);
            $table->index(['right_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pong_matches');
    }
};
