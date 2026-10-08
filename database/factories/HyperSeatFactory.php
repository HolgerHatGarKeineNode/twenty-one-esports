<?php

namespace Database\Factories;

use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A player's seat in a Hyperbitcoinization match. The match factory makes the seats its state names;
 * use this for a seat it does not have.
 *
 * @extends Factory<HyperSeat>
 */
class HyperSeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hyper_match_id' => HyperMatch::factory(),
            'seat' => 0,
            'user_id' => User::factory(),
            'faction' => 'bitcoiner',
            'bot' => false,
            'loot' => 0,
            'timeouts' => 0,
        ];
    }

    public function bot(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'bot' => true]);
    }
}
