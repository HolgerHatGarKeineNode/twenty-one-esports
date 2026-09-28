<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The season planner (P38, App\Support\SeasonChain\SeasonPlans).
     *
     * season_plans: the audit log of the plan for the season after
     * `after_season_id`. Every row holds the full plan after a board change
     * (slug, name, planned Block 0, length in weeks, carry-over factor f in
     * thousandths) and what it changed (`changes`, field => [before, after]);
     * the newest row of the newest season is the plan in force. Rows are
     * never updated.
     *
     * seasons.previous_season_id: the season a later season continues (NIP
     * "Season transition", the `reset` of its ladders); unique, so a season
     * has at most one successor and a second release of a plan fails.
     * seasons.reset_factor_milli: the carry-over factor f the release applied,
     * in thousandths; null for the Pre-Season, which continues nothing.
     */
    public function up(): void
    {
        Schema::create('season_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('after_season_id')->constrained('seasons')->cascadeOnDelete();
            $table->string('slug', 32);
            $table->string('name', 60);
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('weeks');
            $table->unsignedSmallInteger('reset_factor_milli');
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_pubkey', 64);
            $table->json('changes');
            $table->foreignId('announcement_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['after_season_id', 'id']);
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->foreignId('previous_season_id')->nullable()->unique()->after('slug')->constrained('seasons')->nullOnDelete();
            $table->unsignedSmallInteger('reset_factor_milli')->nullable()->after('previous_season_id');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropUnique(['previous_season_id']);
            $table->dropConstrainedForeignId('previous_season_id');
            $table->dropColumn('reset_factor_milli');
        });

        Schema::dropIfExists('season_plans');
    }
};
