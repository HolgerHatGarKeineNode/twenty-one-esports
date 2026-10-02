<?php

namespace Database\Factories;

use App\Games\Blockfill;
use App\Models\LeagueWeek;
use App\Support\Stacker\BlockfillWeeks;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Blockfill draft for next week, on the original rules (bf1).
 *
 * @extends Factory<LeagueWeek>
 */
class LeagueWeekFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game' => Blockfill::SLUG,
            'starts_at' => BlockfillWeeks::endOf(BlockfillWeeks::startOf(now())),
            'settings' => ['difficulty' => 'bf1'],
        ];
    }
}
