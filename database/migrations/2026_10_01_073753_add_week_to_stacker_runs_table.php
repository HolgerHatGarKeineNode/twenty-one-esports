<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill replay storage (plan "Blockfill", P2, security audit round 3):
     * a verified run keeps its replay only while it is among the fastest
     * `replay_keep_top` of its week. `week` is the Monday (Europe/Berlin) the
     * run's week starts on, set when it is verified; the index serves the
     * week's top-N query (App\Support\Stacker\StackerRuns::keepWeekTop()).
     * Additive: older rows keep null.
     */
    public function up(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->string('week', 10)->nullable();
            $table->index(['week', 'status', 'ticks']);
        });
    }

    public function down(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->dropIndex(['week', 'status', 'ticks']);
            $table->dropColumn('week');
        });
    }
};
