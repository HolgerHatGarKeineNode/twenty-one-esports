<?php

namespace Database\Factories;

use App\Models\WeeklySlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A weekly blitz night, Wednesday 20:00 Berlin time.
 *
 * @extends Factory<WeeklySlot>
 */
class WeeklySlotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Blitz night',
            'game' => 'chess',
            'mode' => 'blitz',
            'weekday' => 3,
            'time' => '20:00',
            'timezone' => 'Europe/Berlin',
            'duration_minutes' => 120,
            'active' => true,
        ];
    }
}
