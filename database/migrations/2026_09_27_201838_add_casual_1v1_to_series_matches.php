<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Casual 1v1 without a clan (P23, slice S1; App\Support\Series\CasualMatches):
 *
 * - `series_matches.origin`: how a casual pairing came about (`queue`,
 *   `invite`); null for every other series. `host_side`: the side that opens
 *   the game lobby, drawn at the pairing. `ready_by` and `ready_at_<side>`:
 *   the ready check; the match starts (`start_at`) once both sides are
 *   ready. `lobby_shared_at` / `joined_at`: flags the match room sets; the
 *   lobby itself never reaches the server. `noshow_contested_at`: the
 *   accused side answered a no-show claim. `casual`: the deadlines pinned at
 *   the pairing and each side's queue choice (platform, crossplay).
 * - `series_queue_entries`: one row per player searching right now.
 * - `series_invites`: a direct invite to a player who is looking to play.
 *
 * Additive: existing series keep null and behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->string('origin', 16)->nullable()->index();
            $table->string('host_side', 16)->nullable();
            $table->timestamp('ready_by')->nullable();
            $table->timestamp('ready_at_challenger')->nullable();
            $table->timestamp('ready_at_challenged')->nullable();
            $table->timestamp('lobby_shared_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('noshow_contested_at')->nullable();
            $table->json('casual')->nullable();
        });

        Schema::create('series_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('game', 64);
            $table->string('mode', 32);
            $table->string('platform', 16);
            $table->boolean('crossplay')->default(true);
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->index(['game', 'mode', 'joined_at']);
        });

        Schema::create('series_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('game', 64);
            $table->string('mode', 32);
            $table->string('platform', 16);
            $table->boolean('crossplay')->default(true);
            $table->string('status', 16)->default('pending');
            $table->foreignId('series_match_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
            $table->index(['inviter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_invites');
        Schema::dropIfExists('series_queue_entries');

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropColumn(['origin', 'host_side', 'ready_by', 'ready_at_challenger', 'ready_at_challenged', 'lobby_shared_at', 'joined_at', 'noshow_contested_at', 'casual']);
        });
    }
};
