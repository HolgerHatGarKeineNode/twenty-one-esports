<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Score games (plan "AoE2 und Trackmania", P4, security gate F4): a game
 * account id an admin confirmed as one player's. A player's own claim (the id
 * in users.gamer_tags) is not proven; a confirmed claim wins over every other
 * claim of the same id, and only an admin hands pending runs to a player.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_account_claims', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64);
            $table->string('account_id', 128);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['game', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_account_claims');
    }
};
