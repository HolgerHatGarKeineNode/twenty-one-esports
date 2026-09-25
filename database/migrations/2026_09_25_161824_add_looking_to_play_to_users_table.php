<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Looking to play" (plan, P5 presence): the game and mode a player is
     * up for, as `<game>/<mode>` (e.g. `chess/blitz`); null = not looking.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('looking_to_play', 64)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('looking_to_play');
        });
    }
};
