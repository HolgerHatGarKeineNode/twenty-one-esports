<?php

namespace Database\Factories;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft Swiss blitz tournament for 12 players on one evening, online.
 *
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Blitz Night '.fake()->city(),
            'game' => 'chess',
            'mode' => 'blitz',
            'format' => TournamentFormat::Swiss,
            'options' => FormatOptions::defaults(GameProfile::for('chess', 'blitz'))->toArray(),
            'capacity' => 12,
            'starts_at' => now()->addWeek()->setTime(19, 0),
            'time_window' => 180,
            'on_site' => false,
            'stations' => null,
            'times' => null,
            'results_mode' => TournamentResultsMode::Players,
            'status' => TournamentStatus::Draft,
            'seed' => null,
            'created_by_id' => User::factory(),
        ];
    }

    /**
     * Rocket League 3v3 in the given format with the league's defaults.
     */
    public function rocketLeague(TournamentFormat $format = TournamentFormat::SingleElimination): static
    {
        return $this->state(fn (): array => [
            'name' => 'RL Sunday',
            'game' => 'rocket-league',
            'mode' => '3v3',
            'format' => $format,
            'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '3v3'))->toArray(),
            'capacity' => 8,
        ]);
    }

    public function signup(): static
    {
        return $this->state(fn (): array => ['status' => TournamentStatus::Signup]);
    }
}
