<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropForeign(['white_id']);
            $table->dropForeign(['black_id']);
            $table->foreign('white_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('black_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
