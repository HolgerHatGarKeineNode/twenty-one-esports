<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The casual 1v1 choices a player made last, per game (P23 S3): platform and
 * crossplay, so "Find opponent" starts with them next time
 * (App\Support\Series\CasualLobby::settings()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('casual_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('casual_settings');
        });
    }
};
