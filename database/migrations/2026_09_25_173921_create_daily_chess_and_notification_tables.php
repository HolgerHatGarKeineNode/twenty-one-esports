<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily chess, the NIP-64 game record, notifications and chat mutes (P5b).
     *
     * chess_games:
     * - `pgn_headers`: the PGN tag pairs frozen when the game starts (player
     *   names can change later; a signed record must not).
     * - `record_event_id`: the counted NIP-64 final record (NIP "Game Record":
     *   the first valid one the league accepts).
     * - `reminded_ply`: the ply whose deadline reminder went out, so each turn
     *   gets at most one reminder.
     * - `*_gone_ms`: when the server first confirmed that player's absence from
     *   the game's presence channel (claim-win after the design's timeout).
     * - `*_notify`: per-game choice for "tell me when the opponent moves"
     *   (dm, push, here; null = the account settings), `*_remind` the per-game
     *   deadline reminder switch.
     *
     * chess_moves.nostr_event_id: a daily move is its own signed kind-64 note.
     */
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->json('pgn_headers')->nullable();
            $table->foreignId('record_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->unsignedSmallInteger('reminded_ply')->nullable();
            $table->unsignedBigInteger('white_gone_ms')->nullable();
            $table->unsignedBigInteger('black_gone_ms')->nullable();
            $table->string('white_notify', 8)->nullable();
            $table->string('black_notify', 8)->nullable();
            $table->boolean('white_remind')->default(true);
            $table->boolean('black_remind')->default(true);

            $table->index(['mode', 'status']);
        });

        Schema::table('chess_moves', function (Blueprint $table) {
            $table->foreignId('nostr_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
        });

        Schema::create('chess_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('challenged_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->string('color', 8);
            $table->string('message', 140)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('chess_game_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['challenged_id', 'status']);
            $table->index(['challenger_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('chess_settings')->nullable();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();
            $table->string('public_key', 120);
            $table->string('auth_token', 60);
            $table->timestamps();
        });

        Schema::create('chat_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('muted_pubkey', 64);
            $table->timestamps();

            $table->unique(['user_id', 'muted_pubkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_mutes');
        Schema::dropIfExists('push_subscriptions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chess_settings');
        });

        Schema::dropIfExists('chess_challenges');

        Schema::table('chess_moves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nostr_event_id');
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropIndex(['mode', 'status']);
            $table->dropConstrainedForeignId('record_event_id');
            $table->dropColumn(['pgn_headers', 'reminded_ply', 'white_gone_ms', 'black_gone_ms', 'white_notify', 'black_notify', 'white_remind', 'black_remind']);
        });
    }
};
