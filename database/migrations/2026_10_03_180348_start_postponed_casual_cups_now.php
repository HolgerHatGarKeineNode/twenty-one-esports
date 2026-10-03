<?php

use App\Enums\TournamentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A small casual cup used to move its live evening to the next day after its close
 * (days_after_close). Since 2026-10-03 every cup starts at its close: a drawn or running
 * cup still waiting for that later evening starts now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tournaments')
            ->whereNotNull('cup_series')
            ->whereIn('status', [TournamentStatus::Drawing->value, TournamentStatus::Running->value])
            ->whereNotNull('signup_closes_at')
            ->where('signup_closes_at', '<=', now())
            ->where('starts_at', '>', now())
            ->update(['starts_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
