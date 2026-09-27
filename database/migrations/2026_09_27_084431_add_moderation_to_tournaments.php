<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editing and moderating a tournament before its draw.
     *
     * tournament_signups: an entry an organizer or admin removed keeps its
     * row and its signed consent (the evidence, NIP "Tournament Consent"); it
     * is marked removed, by whom and why, and no longer counts.
     *
     * tournament_bans: players who may not sign up for this tournament again.
     *
     * tournament_moderation_entries: every edit, removal, block and unblock,
     * append-only (who, when, what, why), shown on the edit page.
     */
    public function up(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 500)->nullable();
        });

        Schema::create('tournament_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('tournament_moderation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 80);
            $table->string('action', 32);
            $table->foreignId('tournament_signup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 80)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['tournament_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_moderation_entries');
        Schema::dropIfExists('tournament_bans');

        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by_id');
            $table->dropColumn(['removed_at', 'removal_reason']);
        });
    }
};
