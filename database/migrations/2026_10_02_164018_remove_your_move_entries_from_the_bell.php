<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Your move" no longer goes to the bell (NotificationKind::inBell, user
 * 2026-10-02: one entry per move buried what matters; the floating game bar
 * shows the turn). The entries stored before go too, so the bell is thin at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('type', 'your_move')->delete();
    }

    public function down(): void
    {
        // Deleted entries do not come back; the bell keeps none of this kind from now on.
    }
};
