<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ratings (P7b): one row per rated entity and ladder, plus one history row
     * per result that moved it.
     *
     * ratings:
     * - `pool`: `rated` (the season ladder, from Block 0 on; tiers and badges)
     *   or `casual` (permanent, never a tier, never on Nostr).
     * - `season`: the ladder's season slug for `rated`, '' for `casual` (no
     *   season reset). An empty string instead of null keeps the unique index
     *   effective on every driver.
     * - `subject`: `user:<id>` (chess, a player) or `lineup:<id>` (Rocket
     *   League, a lineup), the key of the unique index; `user_id` / `lineup_id`
     *   carry the same entity as a foreign key so a deleted account or lineup
     *   takes its ratings along.
     * - `results`: rated results so far (wins + draws + losses); below the
     *   provisional count the entity shows no tier.
     *
     * rating_changes: the before/after of one entity for one result. The
     * unique (rating, source, source id) makes applying a result idempotent:
     * a second confirmation or a retry of the same game or series cannot move
     * a rating twice.
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->string('pool', 8);
            $table->string('season', 32)->default('');
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('subject', 32);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lineup_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('rating');
            $table->unsignedInteger('results')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('draws')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->timestamps();

            $table->unique(['pool', 'season', 'game', 'mode', 'subject']);
            $table->index(['pool', 'season', 'game', 'mode', 'rating']);
        });

        Schema::create('rating_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opponent_rating_id')->nullable()->constrained('ratings')->nullOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('match_number')->nullable();
            $table->decimal('score', 2, 1);
            $table->integer('before');
            $table->integer('after');
            $table->integer('delta');
            $table->unsignedInteger('results_before');
            $table->timestamps();

            $table->unique(['rating_id', 'source', 'source_id']);
            $table->index(['source', 'source_id']);
            $table->index(['rating_id', 'opponent_rating_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_changes');
        Schema::dropIfExists('ratings');
    }
};
