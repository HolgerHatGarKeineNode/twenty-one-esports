<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quiet moves (P52, NIP rev. 9.4): no move is an event any more, and the
     * league signs one NIP-64 record per finished game (`record_event_id`).
     * A player may post the game to their own profile once, by button:
     * `white_post_event_id` / `black_post_event_id` hold that player's signed
     * kind-64 copy. `chess_moves.nostr_event_id` stays as the history of the
     * per-move notes signed before this change.
     */
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->foreignId('white_post_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('black_post_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('black_post_event_id');
            $table->dropConstrainedForeignId('white_post_event_id');
        });
    }
};
