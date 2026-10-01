<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Score games (plan "AoE2 und Trackmania", P4, re-audit F4): the log of every
 * admin decision about a game account claim (confirm, reassign, revoke), with
 * who, from whom to whom, why and how many runs moved. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_account_changes', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64);
            $table->string('account_id', 128);
            $table->string('action', 16);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->unsignedInteger('runs_moved')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['game', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_account_changes');
    }
};
