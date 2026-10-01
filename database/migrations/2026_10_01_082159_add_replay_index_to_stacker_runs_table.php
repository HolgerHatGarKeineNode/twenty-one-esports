<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill (security audit round 4): the runs of a week that still hold
     * a replay, for StackerRuns::keepWeekTop(), without scanning the whole
     * week; and the runs waiting for the verifier with a replay, for
     * StackerRuns::inflightFull(). Partial where the database has partial
     * indexes (SQLite, PostgreSQL), a plain index otherwise.
     */
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE INDEX stacker_runs_week_replay_index ON stacker_runs (week) WHERE replay IS NOT NULL');
            DB::statement('CREATE INDEX stacker_runs_status_replay_index ON stacker_runs (status) WHERE replay IS NOT NULL');

            return;
        }

        Schema::table('stacker_runs', function ($table) {
            $table->index('week', 'stacker_runs_week_replay_index');
            $table->index('status', 'stacker_runs_status_replay_index');
        });
    }

    public function down(): void
    {
        Schema::table('stacker_runs', function ($table) {
            $table->dropIndex('stacker_runs_week_replay_index');
            $table->dropIndex('stacker_runs_status_replay_index');
        });
    }
};
