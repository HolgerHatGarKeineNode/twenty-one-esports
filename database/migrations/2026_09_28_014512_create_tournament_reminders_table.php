<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The automatic reminders of a tournament match (P18, slice 5,
     * TournamentReminders): one row per player, waiting state, deadline and
     * reminder point. The unique key makes each reminder go out once, also
     * when two ticks run at the same time; a deadline that moves (a pause)
     * is a new deadline and gets its reminders again.
     */
    public function up(): void
    {
        Schema::create('tournament_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 40);
            $table->string('state', 20);
            $table->timestamp('due_at');
            $table->unsignedInteger('minutes_before');
            $table->timestamp('created_at');
            $table->unique(['subject', 'state', 'due_at', 'minutes_before', 'user_id'], 'tournament_reminders_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_reminders');
    }
};
