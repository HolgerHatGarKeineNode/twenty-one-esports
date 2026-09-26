<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NIP-58 badges (P11, NIP "Rank badges"):
     *
     * - rank_badges: one definition per player, game and mode (`d` =
     *   `rank/<game>/<mode>/<pubkey>`), its current tier and its one award.
     * - rank_badge_versions: every version of a definition the badge key
     *   signed, oldest first; a rank-up share card points at one of them, so
     *   a card never changes after it was posted.
     * - quest_badge_awards: one award per player and quest definition
     *   (`d` = `quest/<slug>`).
     */
    public function up(): void
    {
        Schema::create('rank_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pubkey', 64);
            $table->string('game');
            $table->string('mode');
            $table->string('d')->unique();
            $table->string('badge_pubkey', 64);
            $table->string('tier');
            $table->string('season');
            $table->foreignId('definition_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('award_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pubkey', 'game', 'mode']);
        });

        Schema::create('rank_badge_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rank_badge_id')->constrained()->cascadeOnDelete();
            $table->string('tier');
            $table->string('previous_tier')->nullable();
            $table->string('season');
            $table->integer('rating');
            $table->unsignedBigInteger('signed_at');
            $table->foreignId('nostr_event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quest_badge_awards', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pubkey', 64);
            $table->foreignId('award_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'pubkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_badge_awards');
        Schema::dropIfExists('rank_badge_versions');
        Schema::dropIfExists('rank_badges');
    }
};
