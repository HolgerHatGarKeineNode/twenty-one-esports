<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Running tournaments (P8b).
     *
     * tournaments: the slug (`d` of its NIP-52 `31923`), the sign-up deadline,
     * the published calendar event, and the draw: the Bitcoin block the draw
     * committed to and its hash once mined (the bracket seed), with the `2155`.
     *
     * tournament_signups: one entry per lineup or solo player, with the signed
     * consent (a NIP-98-style event that is stored, never published: the NIP
     * keeps registration off the relays) and its withdrawal.
     *
     * tournament_participants: the players of an entry (`members`), the entry
     * it came from, and a mix team's place in the draw.
     *
     * series_matches / chess_games: the tournament match a normal match plays;
     * `sides` holds a roster side (a mix team or a single RL 1v1 player), which
     * has no lineup.
     *
     * tournament_result_entries: every result a tournament director entered or
     * corrected, append-only (who, when, new and old result).
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('slug', 64)->nullable()->unique();
            $table->timestamp('signup_closes_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->unsignedInteger('draw_height')->nullable();
            $table->string('draw_hash', 64)->nullable();
            $table->foreignId('draw_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
        });

        Schema::create('tournament_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lineup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->json('members');
            $table->foreignId('event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdraw_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();
            $table->index(['tournament_id', 'withdrawn_at']);
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->json('members')->nullable();
            $table->foreignId('tournament_signup_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('draw_position')->nullable();
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->foreignId('tournament_match_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sides')->nullable();
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->foreignId('tournament_match_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('tournament_result_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 80);
            $table->json('result');
            $table->json('previous')->nullable();
            $table->timestamp('created_at');
            $table->index(['tournament_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_result_entries');

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_match_id');
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_match_id');
            $table->dropColumn('sides');
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_signup_id');
            $table->dropColumn(['members', 'draw_position']);
        });

        Schema::dropIfExists('tournament_signups');

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draw_event_id');
            $table->dropConstrainedForeignId('event_id');
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'signup_closes_at', 'published_at', 'draw_height', 'draw_hash']);
        });
    }
};
