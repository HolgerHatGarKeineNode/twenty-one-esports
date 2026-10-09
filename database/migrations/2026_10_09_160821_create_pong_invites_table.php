<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invites to a live Proof of Pong match (plan "Proof of Pong", P2), as board_invites for the board games: one
     * player invites another who is "Looking to play" Proof of Pong; accepting starts the match
     * (`pong_match_id`). An unanswered invite expires.
     */
    public function up(): void
    {
        Schema::create('pong_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->foreignId('pong_match_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
            $table->index(['inviter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pong_invites');
    }
};
