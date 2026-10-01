<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The highscore attempts on /matches (App\Support\Matches\ScoreAttempts):
     * the latest Blockfill runs of a state (verified, or waiting for the
     * verifier) and the latest score runs of a game, newest first, for the
     * mempool strip and the paged table, without reading every run.
     * Additive.
     */
    public function up(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });

        Schema::table('score_runs', function (Blueprint $table) {
            $table->index(['game', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('score_runs', function (Blueprint $table) {
            $table->dropIndex(['game', 'created_at']);
        });
    }
};
