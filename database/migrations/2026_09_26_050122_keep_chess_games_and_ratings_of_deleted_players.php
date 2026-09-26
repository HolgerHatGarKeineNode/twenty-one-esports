<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deleted account no longer takes its chess games and ratings with it
     * (security re-check, item 4): the game, its moves (consensus rule 2
     * stays re-checkable) and both rating changes stay, the deleted side
     * becomes null and is shown as "Deleted player". Signed events were never
     * ours to delete; the league's record of them now stays as well.
     */
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropForeign(['white_id']);
            $table->dropForeign(['black_id']);
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->unsignedBigInteger('white_id')->nullable()->change();
            $table->unsignedBigInteger('black_id')->nullable()->change();
            $table->foreign('white_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('black_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Back to master's schema, NOT NULL included. Games of deleted players
     * (a NULL side) cannot go back without deleting league records, so the
     * rollback refuses while any exist instead of dropping them.
     */
    public function down(): void
    {
        $orphans = DB::table('chess_games')->whereNull('white_id')->orWhereNull('black_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException("Cannot roll back: {$orphans} chess game(s) belong to a deleted player (white_id or black_id is NULL). The old schema would have to delete them; decide about these rows first.");
        }

        Schema::table('ratings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropForeign(['white_id']);
            $table->dropForeign(['black_id']);
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->unsignedBigInteger('white_id')->nullable(false)->change();
            $table->unsignedBigInteger('black_id')->nullable(false)->change();
            $table->foreign('white_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('black_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
