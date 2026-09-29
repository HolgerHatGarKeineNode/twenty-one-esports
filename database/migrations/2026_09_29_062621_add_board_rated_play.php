<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rated board games (plan "Mühle und Dame", P6), as rated chess has them
     * (P7b, P7d) and on the board games' own tables:
     *
     * - board_games.number: the league match number of a rated game (shared
     *   with chess and series, MatchNumber), the `match` of its attestation.
     * - board_games.rated / ladder_address / gate_at_accept / clans_at_accept:
     *   rated at the pairing, pinned to the ladder open then, with the trust
     *   gate and each player's clan read then.
     * - board_queue_entries.rated: a rated search pairs only rated searches.
     *
     * Additive: every existing board game stays casual.
     */
    public function up(): void
    {
        Schema::table('board_games', function (Blueprint $table) {
            $table->unsignedBigInteger('number')->nullable()->unique();
            $table->boolean('rated')->default(false);
            $table->string('ladder_address')->nullable();
            $table->json('gate_at_accept')->nullable();
            $table->json('clans_at_accept')->nullable();
        });

        Schema::table('board_queue_entries', function (Blueprint $table) {
            $table->boolean('rated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('board_queue_entries', function (Blueprint $table) {
            $table->dropColumn('rated');
        });

        Schema::table('board_games', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'rated', 'ladder_address', 'gate_at_accept', 'clans_at_accept']);
        });
    }
};
