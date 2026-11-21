<?php

namespace Database\Factories;

use App\Models\ImpersonationHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open hold: a fresh account whose name reads like an older player's.
 *
 * @extends Factory<ImpersonationHold>
 */
class ImpersonationHoldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pubkey' => fn (array $attributes): string => (string) User::query()->whereKey($attributes['user_id'])->value('pubkey'),
            'matched_user_id' => User::factory(),
            'matched_pubkey' => fn (array $attributes): string => (string) User::query()->whereKey($attributes['matched_user_id'])->value('pubkey'),
            'matched' => ImpersonationHold::NAME,
            'name_skeleton' => 'odel',
            'picture' => null,
            'detected_at' => now(),
        ];
    }

    /** Released by an admin. */
    public function released(): static
    {
        return $this->state(['decided_at' => now(), 'decision' => ImpersonationHold::RELEASED]);
    }
}
