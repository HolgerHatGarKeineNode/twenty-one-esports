<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stream chat bot's posts (P22): one row per kind-1311 message it
     * published, for the cadence (interval, daily cap, the human rule), the
     * rotation (no builder twice within a few posts, no fact twice within
     * hours) and admin visibility.
     */
    public function up(): void
    {
        Schema::create('stream_bot_posts', function (Blueprint $table) {
            $table->id();
            $table->string('builder', 40);
            $table->string('fact_key', 191);
            $table->char('event_id', 64);
            $table->text('content');
            $table->unsignedSmallInteger('relays_accepted');
            $table->unsignedSmallInteger('relays_total');
            $table->timestamp('posted_at');
            $table->timestamp('next_due_at');
            $table->timestamps();

            $table->index('posted_at');
            $table->index(['fact_key', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_bot_posts');
    }
};
