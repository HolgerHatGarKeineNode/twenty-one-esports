<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stream bot's notes on its own profile: one row per subject (a
     * tournament) and kind, claimed before signing so a subject is posted
     * at most once, even with two runs at the same time. The signed event is
     * kept, so a retry after a failed send republishes the very same event
     * (same id) instead of a second note.
     */
    public function up(): void
    {
        Schema::create('bot_posts', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('kind');
            $table->char('event_id', 64)->nullable();
            $table->text('event')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('relays_accepted')->default(0);
            $table->unsignedSmallInteger('relays_total')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_posts');
    }
};
