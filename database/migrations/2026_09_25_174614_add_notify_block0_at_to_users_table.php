<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Notify me at Block 0" (home, pre-launch): when the player asked to be
     * told; null = not asked. Delivery (push, Nostr DM) comes later.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notify_block0_at')->nullable()->after('looking_to_play');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_block0_at');
        });
    }
};
