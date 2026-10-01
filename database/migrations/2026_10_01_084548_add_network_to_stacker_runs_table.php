<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill (security audit round 5): the network a run was submitted
     * from (IPv4 address or IPv6 /64, StackerRuns::network()), so one network
     * holds at most `inflight_per_network` of the runs waiting for the
     * verifier. Additive: older rows keep null.
     */
    public function up(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->string('network', 64)->nullable();
            $table->index(['network', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('stacker_runs', function (Blueprint $table) {
            $table->dropIndex(['network', 'status']);
            $table->dropColumn('network');
        });
    }
};
