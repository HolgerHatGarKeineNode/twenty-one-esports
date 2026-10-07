<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Notify me of new <game> tournaments" (plan "RL-Startseite", P2): one
     * row per player and game; deleting the row unsubscribes.
     */
    public function up(): void
    {
        Schema::create('tournament_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 64);
            $table->timestamps();

            $table->unique(['user_id', 'game']);
            $table->index('game');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_watches');
    }
};
