<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a round really started (P18, the measurement): set once, when the
 * first match of the round starts (TournamentMatchMaker::startReady()).
 * With `closed_at` it gives the round's real duration, which the admin edit
 * page shows next to the estimate and App\Support\Tournaments\RoundTimes
 * aggregates. Additive: existing rounds keep null and are not measured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
