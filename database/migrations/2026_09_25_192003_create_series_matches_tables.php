<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Series matches between two lineups (P6a, Rocket League).
     *
     * match_numbers: the league's running match number over all games and
     * modes (NIP tag `match`). A number is reserved for one author before the
     * challenge is signed and used by exactly one challenge; numbers of casual
     * matches and of challenges that are never sent leave gaps.
     *
     * series_matches: one row per challenge, from "open" to its final state.
     * - Side data (`*_name`, `*_tag`, `*_lineup_address`) is frozen at
     *   creation, so a match still reads right after a clan changes or ends.
     * - `lobby_name` / `lobby_password` are encrypted at rest and never leave
     *   the room of the two lineups (NIP rule 6: never in an event).
     * - `live_games`: the per-game score entered in the room, shown publicly
     *   as provisional; `rosters`: who played, per side, set by each captain.
     * - `result_games`, `winner`, `resolution`: the final result, confirmed by
     *   both captains or decided by an admin (NIP `resolution`).
     *
     * series_reports: every "Submit final score" (NIP 2152) with the other
     * side's answer (2153). A new report after a dispute supersedes the old
     * one; all stay for the history.
     *
     * dispute_evidence: screenshots for a dispute, admins only, never published.
     */
    public function up(): void
    {
        Schema::create('match_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create('series_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('number')->unique();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->unsignedTinyInteger('best_of');
            $table->boolean('rated')->default(false);

            $table->foreignId('challenger_lineup_id')->nullable()->constrained('lineups')->nullOnDelete();
            $table->foreignId('challenged_lineup_id')->nullable()->constrained('lineups')->nullOnDelete();
            $table->string('challenger_name');
            $table->string('challenged_name');
            $table->string('challenger_tag', 8);
            $table->string('challenged_tag', 8);
            $table->string('challenger_lineup_address');
            $table->string('challenged_lineup_address');
            $table->string('ladder_address')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16);
            $table->json('proposals');
            $table->timestamp('respond_by');
            $table->timestamp('start_at')->nullable();
            $table->string('message', 140)->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();

            $table->text('lobby_name')->nullable();
            $table->text('lobby_password')->nullable();
            $table->string('lobby_region', 16)->nullable();
            $table->foreignId('lobby_updated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('live_games')->nullable();
            $table->json('rosters')->nullable();

            $table->string('noshow_side', 16)->nullable();
            $table->timestamp('noshow_reported_at')->nullable();
            $table->timestamp('new_report_requested_at')->nullable();

            $table->json('result_games')->nullable();
            $table->string('winner', 16)->nullable();
            $table->string('resolution', 16)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('challenge_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('answer_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'respond_by']);
            $table->index(['challenger_lineup_id', 'status']);
            $table->index(['challenged_lineup_id', 'status']);
        });

        Schema::create('series_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 16);
            $table->json('games');
            $table->json('roster');
            $table->string('status', 16);
            $table->foreignId('event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('responded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('response_reason', 280)->nullable();
            $table->foreignId('response_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('name', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('series_reports');
        Schema::dropIfExists('series_matches');
        Schema::dropIfExists('match_numbers');
    }
};
