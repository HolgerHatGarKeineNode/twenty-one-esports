<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `season-chain-v2`: a season halves by the amount a share key mined, never by the calendar. The genesis signs
     * the number of eras of every share key (`eras`) instead of the seconds an era lasts (`halving`), so the column
     * follows. No season was released under the calendar rule, so no row is converted; the default of 6 is the
     * chain draft's (config/season.php) and only lets the column be added to a table that has rows.
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->unsignedSmallInteger('eras')->default(6);
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('halving_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->unsignedInteger('halving_seconds')->default(28 * 86400);
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('eras');
        });
    }
};
