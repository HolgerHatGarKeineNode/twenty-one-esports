<?php

namespace Database\Factories;

use App\Enums\BoardInviteStatus;
use App\Models\PongInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open invite to a live Proof of Pong match. Invites people send are made by App\Support\Pong\PongInvites.
 *
 * @extends Factory<PongInvite>
 */
class PongInviteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inviter_id' => User::factory(),
            'invitee_id' => User::factory(),
            'status' => BoardInviteStatus::Pending,
            'expires_at' => now()->addMinutes(2),
        ];
    }
}
