<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rules change no longer asks the entries to confirm again: an entry
     * stays valid through the draw. The consents already signed stay in
     * `nostr_events`; only the link from the entry goes.
     */
    public function up(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconfirm_event_id');
            $table->dropColumn('reconfirm_since');
        });
    }

    /**
     * The columns come back empty: no entry waits for a new consent.
     */
    public function down(): void
    {
        Schema::table('tournament_signups', function (Blueprint $table) {
            $table->timestamp('reconfirm_since')->nullable();
            $table->foreignId('reconfirm_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
        });
    }
};
