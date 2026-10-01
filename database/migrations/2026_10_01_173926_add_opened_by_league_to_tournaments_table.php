<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The positive mark of a score window the league opened itself (security
     * audit of plan "AoE2 und Trackmania", P7, F1): only such a window can
     * mine a solo block (SeasonChains::attestScoreWindow()). `created_by_id`
     * null is no such mark: deleting an organizer's account sets it too
     * (nullOnDelete). Set only by the league's own code (BlockfillWeeks::open()),
     * never mass assignable.
     *
     * The weeks of Blockfill opened so far are the league's: organizers can
     * never set up a Blockfill tournament, and each week's slug is
     * `blockfill-<monday>`.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('opened_by_league')->default(false)->after('created_by_id');
        });

        DB::table('tournaments')->where('game', 'blockfill')->whereNull('created_by_id')->where('slug', 'like', 'blockfill-____-__-__')
            ->update(['opened_by_league' => true]);
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('opened_by_league');
        });
    }
};
