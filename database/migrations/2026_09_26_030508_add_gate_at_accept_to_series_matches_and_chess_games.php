<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trust gate as it stood at the accept (P7d, NIP "Trust gate":
     * "Nothing after the accept undoes the gate"): the trust key and minimum,
     * the gatekeepers, whether they listed each other, and per player the
     * rank, the assertion id and the opponent-list id
     * (App\Support\SeasonChain\GatePin). Rating, consensus rule 1 and the
     * 2154 `gate` rows read only this; null for casual play and for rated
     * play pinned before this column (which then rates nothing).
     */
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->json('gate_at_accept')->nullable()->after('clans_at_accept');
        });

        // A rated chess game is paired by the league (the queue); the pairing
        // is its accept. Its clans are pinned then too (rule 3, `clan` rows).
        Schema::table('chess_games', function (Blueprint $table) {
            $table->json('gate_at_accept')->nullable()->after('rated');
            $table->json('clans_at_accept')->nullable()->after('gate_at_accept');
        });
    }

    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn('gate_at_accept');
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropColumn(['gate_at_accept', 'clans_at_accept']);
        });
    }
};
