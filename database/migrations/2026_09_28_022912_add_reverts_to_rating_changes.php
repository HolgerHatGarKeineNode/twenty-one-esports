<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A correction of a rated result (TournamentControl::setResult(),
     * RatingService::correct()) reverts the result's rating changes and may
     * record them anew for the corrected outcome. Nothing is deleted:
     *
     * - `reverted_at`: when a correction took this change back; a reverted
     *   row no longer counts anywhere (RatingChange's `live` scope) and stays
     *   as the audit trail.
     * - `revision`: 0 for the result as first rated, n for its n-th
     *   correction. The unique (rating, source, source id) becomes
     *   (rating, source, source id, revision): applying a result stays
     *   idempotent, and two concurrent corrections of one result cannot both
     *   write the same revision.
     */
    public function up(): void
    {
        Schema::table('rating_changes', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('reverted_at')->nullable();
        });

        Schema::table('rating_changes', function (Blueprint $table) {
            $table->dropUnique(['rating_id', 'source', 'source_id']);
            $table->unique(['rating_id', 'source', 'source_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::table('rating_changes', function (Blueprint $table) {
            $table->dropUnique(['rating_id', 'source', 'source_id', 'revision']);
            $table->unique(['rating_id', 'source', 'source_id']);
        });

        Schema::table('rating_changes', function (Blueprint $table) {
            $table->dropColumn(['revision', 'reverted_at']);
        });
    }
};
