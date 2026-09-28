<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * series_match_players: the players of a series' roster sides
     * (`series_matches.sides`: a tournament's 1v1 or mix team, a casual 1v1),
     * one row per player and side, so "the series this player plays" is an
     * index lookup instead of a JSON scan of every open series
     * (App\Support\Dock\OpenMatches). Kept in step by SeriesMatch whenever
     * `sides` is written; `sides` stays the source. Existing series are
     * backfilled here.
     */
    public function up(): void
    {
        Schema::create('series_match_players', function (Blueprint $table) {
            $table->foreignId('series_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('side', 16);

            $table->primary(['series_match_id', 'side', 'user_id']);
            $table->index(['user_id', 'series_match_id']);
        });

        DB::table('series_matches')->whereNotNull('sides')->select(['id', 'sides'])->orderBy('id')
            ->chunkById(500, function ($matches): void {
                $rows = [];

                foreach ($matches as $match) {
                    $sides = json_decode((string) $match->sides, true);

                    foreach (['challenger', 'challenged'] as $side) {
                        foreach (array_unique(array_map(intval(...), is_array($sides[$side] ?? null) ? $sides[$side] : [])) as $userId) {
                            $rows[] = ['series_match_id' => $match->id, 'user_id' => $userId, 'side' => $side];
                        }
                    }
                }

                // A player deleted since (no users row) has nothing to look up.
                $known = array_flip(DB::table('users')->whereIn('id', array_unique(array_column($rows, 'user_id')))->pluck('id')->map(intval(...))->all());
                $rows = array_values(array_filter($rows, fn (array $row): bool => isset($known[$row['user_id']])));

                foreach (array_chunk($rows, 300) as $chunk) {
                    DB::table('series_match_players')->insertOrIgnore($chunk);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_match_players');
    }
};
