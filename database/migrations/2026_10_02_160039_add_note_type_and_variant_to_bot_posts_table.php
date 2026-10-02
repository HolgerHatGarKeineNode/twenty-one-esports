<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Variety and pacing of the stream bot's profile notes (ProfileNotes):
     * `note_type` names what a note is ("tournament", "free_places",
     * "blockfill_top", "pride_win", …) for the per-type cooldown and daily
     * cap, `variant` the wording it was signed with, so the next note of the
     * type takes the next wording and never repeats the last one. Both are
     * set when the note is signed; rows from before stay null and count for
     * neither.
     */
    public function up(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->string('note_type', 40)->nullable()->after('slot');
            $table->unsignedTinyInteger('variant')->nullable()->after('note_type');
            $table->index(['note_type', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->dropIndex(['note_type', 'published_at']);
            $table->dropColumn(['note_type', 'variant']);
        });
    }
};
