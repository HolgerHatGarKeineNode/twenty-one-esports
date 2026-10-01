<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockfill controls (plan "Blockfill", P3): the player's handling
     * (DAS/ARR/SDF) and key bindings, saved on the gaming settings page
     * (App\Support\Stacker\StackerSettings). Null = the defaults. Additive.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('stacker_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stacker_settings');
        });
    }
};
