<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The weekly quest counts a player's verified runs of one week: an index on the player and the time.
     */
    public function up(): void
    {
        Schema::table('score_runs', function (Blueprint $table) {
            $table->index(['user_id', 'achieved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('score_runs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'achieved_at']);
        });
    }
};
