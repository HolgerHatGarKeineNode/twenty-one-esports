<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled casual 1v1 (P23 S4, App\Support\Series\CasualChallenges): the
 * two notifications `casual:tick` sends once per match before the agreed
 * start, the reminder (`reminded_at`) and "check-in is open"
 * (`checkin_opened_at`). The check-in itself uses the ready columns of the
 * instant match. Additive: existing rows keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('checkin_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn(['reminded_at', 'checkin_opened_at']);
        });
    }
};
