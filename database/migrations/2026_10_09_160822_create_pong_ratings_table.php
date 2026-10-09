<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's Proof of Pong Elo (plan "Proof of Pong", P2): one row per player, permanent (no season), moved by
     * rated live matches only (App\Support\Pong\PongRatings). Each match keeps both players' rating before and after
     * on its own row (pong_matches.*_rating_before/after), which is the change's record.
     */
    public function up(): void
    {
        Schema::create('pong_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('rating');
            $table->unsignedInteger('results')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->timestamps();

            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pong_ratings');
    }
};
