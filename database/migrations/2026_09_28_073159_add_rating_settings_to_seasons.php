<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin rating settings (P35, App\Support\Rating\RatingSettings).
     *
     * seasons.rating_parameters: the rating, rank and hashrate values the
     * season's ladders (`32152`) froze at Block 0; null for a season released
     * before P35, which then reads config/season.php, the values it was
     * released with.
     *
     * season_setting_changes: the audit log of the draft for the next Block
     * 0. Every row holds the full draft after the change (`values`) and what
     * it changed (`changes`, dot path => [before, after]); the newest row is
     * the draft, no row means config/season.php.
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->json('rating_parameters')->nullable()->after('parameters');
        });

        Schema::create('season_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_pubkey', 64);
            $table->json('values');
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_setting_changes');

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('rating_parameters');
        });
    }
};
