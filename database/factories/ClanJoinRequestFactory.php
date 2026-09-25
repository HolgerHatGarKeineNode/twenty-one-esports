<?php

namespace Database\Factories;

use App\Enums\JoinRequestStatus;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending join request of a fresh player to a fresh clan.
 *
 * @extends Factory<ClanJoinRequest>
 */
class ClanJoinRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clan_id' => Clan::factory(),
            'user_id' => User::factory(),
            'status' => JoinRequestStatus::Pending,
        ];
    }
}
