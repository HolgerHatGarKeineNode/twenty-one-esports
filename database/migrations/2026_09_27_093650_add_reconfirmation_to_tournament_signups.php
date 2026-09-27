<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rules change after sign-ups (results mode, directors, game or mode,
     * format) leaves every entry's consent (22150) naming the superseded
     * version. The entry waits for a new consent against the current
     * version from `reconfirm_since` on; `reconfirm_event_id` is that new
     * consent. An entry still waiting when sign-up closes is dropped.
     */
    public function up(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->timestamp('reconfirm_since')->nullable();
            $table->foreignId('reconfirm_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconfirm_event_id');
            $table->dropColumn('reconfirm_since');
        });
    }
};
