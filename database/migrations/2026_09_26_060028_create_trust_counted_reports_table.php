<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reports that counted in a season (security re-check round 4): a
     * report that counted once keeps its place in the author and subtree
     * caps for the rest of the season, so later or backdated reports cannot
     * push it out. Author, target and subtree are kept as they were when it
     * first counted.
     */
    public function up(): void
    {
        Schema::create('trust_counted_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->string('author', 64);
            $table->string('target', 64);
            $table->string('subtree', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['season_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_counted_reports');
    }
};
