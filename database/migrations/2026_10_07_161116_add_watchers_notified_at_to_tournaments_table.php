<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the players watching the tournament's game were told it opened
     * (App\Jobs\NotifyTournamentWatchers); null = not yet. Claimed once, so a
     * second run tells no one twice.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('watchers_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('watchers_notified_at');
        });
    }
};
