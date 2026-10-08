<?php

namespace Database\Factories;

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Support\Hyper\HyperGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live Hyperbitcoinization match of two bots (bitcoiner, fed) at its first turn, with its seats. Matches
 * that people play are made by App\Support\Hyper\HyperMatches::create(); this is for tests and seeding.
 *
 * @extends Factory<HyperMatch>
 */
class HyperMatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seed = random_int(0, 0xFFFFFFFF);
        $state = HyperGame::start([['faction' => 'bitcoiner', 'bot' => true], ['faction' => 'fed', 'bot' => true]], 0, $seed)->game->toArray();

        return [
            'mode' => HyperMatch::LIVE,
            'status' => HyperMatchStatus::Active,
            'seed' => $seed,
            'round_limit' => 0,
            'rated' => false,
            'state' => $state,
            'ply' => 0,
            'current_seat' => $state['seat'],
            'turn_started_ms' => (int) now()->getTimestampMs(),
            'deadline_ms' => null,
        ];
    }

    /**
     * The seats the state names, one row each, unless the test made its own.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (HyperMatch $match): void {
            if ($match->seats()->exists()) {
                return;
            }

            foreach ($match->state['seats'] as $index => $seat) {
                HyperSeat::query()->create(['hyper_match_id' => $match->id, 'seat' => $index, 'faction' => $seat['faction'], 'bot' => $seat['bot']]);
            }
        });
    }
}
