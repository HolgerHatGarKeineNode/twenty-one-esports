<?php

namespace Database\Factories;

use App\Enums\ClanRole;
use App\Enums\LineupRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Rocket League lineup of a fresh clan. `ready()` seats the clan owner as
 * captain and fills the mode with accepted players (plus optional subs), so
 * the lineup can take a challenge. No signed events.
 *
 * @extends Factory<Lineup>
 */
class LineupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clan_id' => Clan::factory(),
            'game' => 'rocket-league',
            'mode' => '3v3',
        ];
    }

    public function mode(string $mode): static
    {
        return $this->state(['mode' => $mode]);
    }

    public function ready(int $substitutes = 0): static
    {
        return $this->afterCreating(function (Lineup $lineup) use ($substitutes): void {
            $clan = $lineup->clan;
            $size = $lineup->gameMode()->teamSize;

            LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $clan->owner_id, 'role' => LineupRole::Captain, 'accepted_at' => now()]);

            for ($i = 1; $i < $size + $substitutes; $i++) {
                $player = User::factory()->create();
                ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $player->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
                LineupSeat::query()->create([
                    'lineup_id' => $lineup->id,
                    'user_id' => $player->id,
                    'role' => $i < $size ? LineupRole::Player : LineupRole::Substitute,
                    'accepted_at' => now(),
                ]);
            }
        });
    }
}
