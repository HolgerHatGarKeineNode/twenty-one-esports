<?php

namespace Database\Factories;

use App\Enums\SeriesStatus;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A casual Rocket League series between two ready 3v3 lineups, open for an
 * answer. `accepted()` moves it into the match room with the start behind
 * it. Tests that need the Nostr side go through SeriesService.
 *
 * @extends Factory<SeriesMatch>
 */
class SeriesMatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lineup = fn (string $key) => fn (array $attributes): Lineup => Lineup::query()->with('clan')->whereKey($attributes[$key])->firstOrFail();

        return [
            'challenger_lineup_id' => Lineup::factory()->ready(),
            'challenged_lineup_id' => Lineup::factory()->ready(),
            'number' => fn (array $attributes) => MatchNumber::query()->create([
                'user_id' => $lineup('challenger_lineup_id')($attributes)->clan->owner_id,
                'used_at' => now(),
            ])->id,
            'created_by_id' => fn (array $attributes) => $lineup('challenger_lineup_id')($attributes)->clan->owner_id,
            'game' => 'rocket-league',
            'mode' => fn (array $attributes) => $lineup('challenger_lineup_id')($attributes)->mode,
            'best_of' => 3,
            'rated' => false,
            'challenger_name' => fn (array $attributes) => $lineup('challenger_lineup_id')($attributes)->clan->name,
            'challenged_name' => fn (array $attributes) => $lineup('challenged_lineup_id')($attributes)->clan->name,
            'challenger_tag' => fn (array $attributes) => $lineup('challenger_lineup_id')($attributes)->clan->clantag,
            'challenged_tag' => fn (array $attributes) => $lineup('challenged_lineup_id')($attributes)->clan->clantag,
            'challenger_lineup_address' => fn (array $attributes) => $lineup('challenger_lineup_id')($attributes)->address(),
            'challenged_lineup_address' => fn (array $attributes) => $lineup('challenged_lineup_id')($attributes)->address(),
            'status' => SeriesStatus::Open,
            'proposals' => fn () => [now()->addHours(3)->startOfMinute()->getTimestamp()],
            'respond_by' => fn () => now()->addHours(2)->startOfMinute(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => SeriesStatus::Accepted,
            'proposals' => [now()->subMinutes(30)->startOfMinute()->getTimestamp()],
            'respond_by' => now()->subMinutes(40),
            'start_at' => now()->subMinutes(30)->startOfMinute(),
            'answered_at' => now()->subMinutes(45),
        ]);
    }
}
