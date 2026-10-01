<?php

namespace Database\Factories;

use App\Enums\StackerRunStatus;
use App\Models\StackerRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A freshly issued Blockfill run of a fresh player, on the current engine.
 *
 * @extends Factory<StackerRun>
 */
class StackerRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'seed' => bin2hex(random_bytes(16)),
            'engine' => (string) config('esports.blockfill.engine'),
            'status' => StackerRunStatus::Issued,
            'issued_at' => now(),
        ];
    }

    /**
     * A run whose replay was verified at `ticks`.
     */
    public function verified(int $ticks): static
    {
        return $this->state(fn (): array => [
            'status' => StackerRunStatus::Verified,
            'ticks' => $ticks,
            'state_hash' => '00000000',
            'submitted_at' => now(),
            'verified_at' => now(),
        ]);
    }
}
