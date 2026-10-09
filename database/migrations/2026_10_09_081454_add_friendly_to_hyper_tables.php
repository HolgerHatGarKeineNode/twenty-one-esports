<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hyperbitcoinization friendly matches (plan "Hyperbitcoinization", P5c, user 2026-10-09): a lobby table may be
     * opened as a friendly match, whose match is never rated even without bots in a live season. A rematch table
     * carries the old match's flag (unrated old match, friendly rematch).
     */
    public function up(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->boolean('friendly')->default(false)->after('team_clans');
        });
    }

    public function down(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->dropColumn('friendly');
        });
    }
};
