<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Engagement for the season start (P10). League data only: nothing here
     * is a Nostr event, and none of it counts toward ratings, trust, blocks
     * or rewards.
     *
     * weekly_slots: a recurring event an admin defines ("Blitz night,
     * Wednesday 20:00"): ISO weekday 1 (Monday) … 7 (Sunday), a wall-clock
     * time in the slot's own time zone, so it stays at 20:00 across DST.
     *
     * slot_events: the concrete dates the scheduler made from a slot. The
     * unique (slot, start) makes a second run of the scheduler a no-op.
     *
     * placement_reveals: the rank a player placed into after their fifth
     * rated result in a ladder (`season.rating.provisional`). One row per
     * player and rating; `shown_at` is claimed with one conditional UPDATE,
     * so the reveal shows exactly once, even with two tabs open.
     *
     * quest_credits: one row per player, quest, week and result. Progress is
     * the number of rows, so a result counted twice (a double report, a
     * retried job) still counts once.
     *
     * cosmetics: one row per player and cosmetic; `source` says where it came
     * from (for the invite frame: the invite use).
     */
    public function up(): void
    {
        Schema::create('weekly_slots', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->unsignedTinyInteger('weekday');
            $table->string('time', 5);
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('slot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_slot_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->unique(['weekly_slot_id', 'starts_at']);
            $table->index('ends_at');
        });

        Schema::create('placement_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_id')->constrained()->cascadeOnDelete();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->integer('rating');
            $table->string('tier', 32);
            $table->timestamp('shown_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'rating_id']);
            $table->index(['user_id', 'shown_at']);
        });

        Schema::create('quest_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('quest', 32);
            $table->string('period', 16);
            $table->string('source', 48);
            $table->timestamps();

            $table->unique(['user_id', 'quest', 'period', 'source']);
        });

        Schema::create('cosmetics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cosmetic', 32);
            $table->string('source', 64);
            $table->timestamps();

            $table->unique(['user_id', 'cosmetic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmetics');
        Schema::dropIfExists('quest_credits');
        Schema::dropIfExists('placement_reveals');
        Schema::dropIfExists('slot_events');
        Schema::dropIfExists('weekly_slots');
    }
};
