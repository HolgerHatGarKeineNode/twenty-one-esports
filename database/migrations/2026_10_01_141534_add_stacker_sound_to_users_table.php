<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill sound (plan "Blockfill", P8): effects and music, each on/off
     * with its own volume, set with the control on the game page
     * (App\Support\Stacker\StackerSettings::sound()). Its own column, so the
     * gaming settings page, which writes stacker_settings as a whole, never
     * overwrites it. Null = the defaults (effects on, music off). Additive.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('stacker_sound')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stacker_sound');
        });
    }
};
