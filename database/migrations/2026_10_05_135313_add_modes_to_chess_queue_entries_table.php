<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Either" in the live chess queue (plan "Schach Rapid und Clan", P2; user,
 * 2026-10-05): a player may search rapid OR blitz and is paired with the
 * first fitting opponent of either. `modes` lists every mode the entry
 * takes; null = only `mode`, as every row before this one. `mode` stays the
 * entry's first choice: the game mode when both entries take both.
 *
 * Additive: the queue holds seconds-old rows, existing ones keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chess_queue_entries', function (Blueprint $table) {
            $table->json('modes')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('chess_queue_entries', function (Blueprint $table) {
            $table->dropColumn('modes');
        });
    }
};
