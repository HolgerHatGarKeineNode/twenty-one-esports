<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invite deep links `/i/{code}` and clan join requests (P6b). League data
     * only: nothing here is a Nostr event (docs/nips/esports.md, "Invite links
     * and join requests").
     *
     * invite_links:
     * - `code`: 22 base62 characters from random_bytes (~131 bits), the whole
     *   secret of the link. Unique, never derived from an id.
     * - `type`: blitz | daily | series | clan (App\Enums\InviteLinkType).
     * - `options`: what the inviter chose (series: lineup, best of, proposed
     *   starts; daily: colour).
     * - `max_uses`: 1 for a one-time link, null for "several times"; `uses`
     *   is claimed with one conditional UPDATE, so two strangers racing for a
     *   one-time link cannot both win.
     *
     * invite_link_uses: who took which link, and the referral (who invited
     * whom). `was_new` marks a player whose account was created by the login
     * they started on the invite. Referrals never count toward ratings, blocks or
     * rewards (plan: "Einladungen zählen nie für Rewards").
     *
     * clan_join_requests: a player asks to join a clan through a clan link.
     * A captain confirms; the owner then lists the player in the clan event
     * (kind 32150) through the named-invite flow, and the player joins with
     * their own membership (12150) as before.
     */
    public function up(): void
    {
        Schema::create('invite_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 16);
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('options')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['inviter_id', 'type']);
        });

        Schema::create('invite_link_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invite_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('was_new')->default(false);
            $table->foreignId('chess_game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('series_match_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['invite_link_id', 'user_id']);
            $table->index(['inviter_id', 'user_id']);
        });

        Schema::create('clan_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invite_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('clan_invite_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['clan_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_join_requests');
        Schema::dropIfExists('invite_link_uses');
        Schema::dropIfExists('invite_links');
    }
};
