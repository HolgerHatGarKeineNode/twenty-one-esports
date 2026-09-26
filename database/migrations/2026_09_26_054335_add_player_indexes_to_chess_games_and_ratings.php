<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL indexes no foreign key by itself: deleting a user (ON DELETE
     * SET NULL on these columns) scanned the whole table, 377 ms at 2M games,
     * and player pages query by them too. No existing index leads with them
     * (chess_games: status + deadline_ms; ratings: pool, season, game, mode).
     */
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->index('white_id');
            $table->index('black_id');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropIndex(['white_id']);
            $table->dropIndex(['black_id']);
        });
    }
};
