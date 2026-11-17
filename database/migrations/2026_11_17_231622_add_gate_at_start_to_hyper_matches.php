<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a rated Hyperbitcoinization match pins when it starts, for the season chain (NIP rev. 9.26, draft): every
     * seat's trust rank, assertion and anchor, the pairs of seats that list each other as opponents, and every
     * seat's clan (App\Support\Hyper\HyperMining::pinAtStart()). Null for every match begun while the game has no
     * weight in the chain, so nothing changes until the board gives it one; a match without it never mines.
     */
    public function up(): void
    {
        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->json('gate_at_start')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->dropColumn('gate_at_start');
        });
    }
};
