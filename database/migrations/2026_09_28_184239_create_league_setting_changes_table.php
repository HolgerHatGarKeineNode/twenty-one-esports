<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * league_setting_changes (P44): the append-only log of the league
     * settings an admin changed on /admin/settings. One row per changed key:
     * who, when, the value in force before and the new one (`after` null =
     * back to the default of the config). The newest row of a key is its
     * value in force (App\Support\Settings\LeagueSettings).
     */
    public function up(): void
    {
        Schema::create('league_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_pubkey', 64);
            $table->timestamps();

            $table->index(['key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_setting_changes');
    }
};
