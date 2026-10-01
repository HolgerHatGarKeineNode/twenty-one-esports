<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Score games (plan "AoE2 und Trackmania", P4, round-3 audit):
 * - score_account_tags: when a player stored their account id of a score
 *   game, so a leaderboard waits only for ids stored before its window
 *   closed (S1);
 * - the account log keeps the pubkeys of the deciding admin and of the
 *   players it moved runs from and to, so a deleted account does not erase
 *   who decided (S5, as trust_decisions does).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_account_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 64);
            $table->string('account_id', 128);
            $table->timestamp('stored_at');

            $table->unique(['user_id', 'game']);
            $table->index(['game', 'account_id']);
        });

        Schema::table('score_account_changes', function (Blueprint $table) {
            $table->string('admin_pubkey', 64)->nullable();
            $table->string('from_pubkey', 64)->nullable();
            $table->string('to_pubkey', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('score_account_changes', function (Blueprint $table) {
            $table->dropColumn(['admin_pubkey', 'from_pubkey', 'to_pubkey']);
        });

        Schema::dropIfExists('score_account_tags');
    }
};
