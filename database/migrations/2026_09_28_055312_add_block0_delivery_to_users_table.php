<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery of "Notify me at Block 0" (App\Support\Notifications\BlockZeroNotifications):
     * `block0_notified_at` = when the player was told that Block 0 is
     * released, `block0_heads_up_for` = the planned Block 0 date the player
     * was last told about (null = none). Each is claimed before the
     * notification goes out, so a second run sends nothing.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('block0_notified_at')->nullable()->after('notify_block0_at');
            $table->timestamp('block0_heads_up_for')->nullable()->after('block0_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['block0_notified_at', 'block0_heads_up_for']);
        });
    }
};
