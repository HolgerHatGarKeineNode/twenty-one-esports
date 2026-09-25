<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One league match number for every game (P7b): chess games take theirs from
 * the same `match_numbers` sequence as Rocket League series, so "#12" on
 * /matches is one game, not a series and a chess game at once.
 *
 * Existing games get a number in the order they were created, after every
 * number handed out so far; series keep theirs. Each number is a used
 * `match_numbers` row of the game's White player, like a series number is a
 * used row of its author.
 *
 * The column stays nullable at the schema level (a Postgres table with rows
 * cannot take a NOT NULL column without a default); App\Models\ChessGame
 * fills it on every create, and the unique index keeps numbers apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chess_games', function (Blueprint $table) {
            $table->unsignedBigInteger('number')->nullable()->after('id');
        });

        DB::table('chess_games')->whereNull('number')->orderBy('id')->select(['id', 'white_id', 'created_at', 'ended_at'])
            ->chunkById(500, function ($games): void {
                foreach ($games as $game) {
                    $at = $game->created_at ?? now();
                    $number = DB::table('match_numbers')->insertGetId([
                        'user_id' => $game->white_id,
                        'used_at' => $at,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);

                    DB::table('chess_games')->where('id', $game->id)->update(['number' => $number]);
                }
            });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->unique('number');
        });
    }

    /**
     * The chess numbers go back into the sequence as gaps: their rows are
     * removed, series numbers stay untouched.
     */
    public function down(): void
    {
        DB::table('match_numbers')->whereIn('id', DB::table('chess_games')->whereNotNull('number')->select('number'))->delete();

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
