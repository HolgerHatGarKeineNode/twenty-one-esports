<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    /**
     * Dropping the reports that counted in the live season would let
     * backdated reports push them out again for the rest of it. Rows of
     * ended seasons hold nothing back. "Live" as Seasons::live() defines it.
     */
    public function down(): void
    {
        $now = now();
        $counted = Schema::hasTable('trust_counted_reports') ? DB::table('trust_counted_reports')
            ->join('seasons', 'seasons.id', '=', 'trust_counted_reports.season_id')
            ->where('seasons.genesis_at', '<=', $now)->where('seasons.ends_at', '>', $now)->count() : 0;

        if ($counted > 0) {
            throw new RuntimeException("Cannot roll back: the live season holds {$counted} counted report(s). Dropping them would let backdated reports push them out again; roll back after the season has ended.");
        }

        Schema::dropIfExists('trust_counted_reports');
    }
};
