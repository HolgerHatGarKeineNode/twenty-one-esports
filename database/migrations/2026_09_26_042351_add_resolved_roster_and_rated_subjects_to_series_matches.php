<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * resolved_roster: the roster of an admin decision (NIP "League
     * Attestation": with `admin` the roster is the league's decision), so a
     * result decided without any report still names its winners.
     *
     * rated_subjects: the two rated entities pinned at a rated accept
     * (`lineup:<id>` per side), so the result is rated even if a lineup is
     * gone by then (security gate F3).
     */
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->json('resolved_roster')->nullable()->after('result_games');
            $table->json('rated_subjects')->nullable()->after('gate_at_accept');
        });
    }

    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn(['resolved_roster', 'rated_subjects']);
        });
    }
};
