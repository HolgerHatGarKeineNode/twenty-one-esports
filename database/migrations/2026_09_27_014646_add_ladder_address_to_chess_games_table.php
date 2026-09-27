<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ladder a rated chess game was paired on (P8c): pinned when the game
 * starts, so a game finished after its season closed is never rated or
 * attested on a later season's ladder (NIP rule 16). Additive and nullable:
 * existing rows keep null, which is never rated (fail closed); before Block 0
 * no rated game exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->string('ladder_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropColumn('ladder_address');
        });
    }
};
