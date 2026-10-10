<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The break scene's state (plan "OBS-Broadcast-Overlays", P5): null follows the tournament and the clock
     * ("starting soon" before a start, "break" while it runs, "thanks for watching" once it is over); `soon`, `break`
     * or `end` pins one. Only the break variant reads it.
     */
    public function up(): void
    {
        Schema::table('overlay_presets', function (Blueprint $table) {
            $table->string('scene', 8)->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('overlay_presets', function (Blueprint $table) {
            $table->dropColumn('scene');
        });
    }
};
