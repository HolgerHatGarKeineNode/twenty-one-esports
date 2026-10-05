<?php

namespace Database\Factories;

use App\Enums\ModerationAction;
use App\Models\PubkeyModeration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A site-wide mute in force of a random key, by a random admin key.
 *
 * @extends Factory<PubkeyModeration>
 */
class PubkeyModerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pubkey' => bin2hex(random_bytes(32)),
            'action' => ModerationAction::Mute,
            'reason' => 'Spam in the chat',
            'actor_pubkey' => bin2hex(random_bytes(32)),
        ];
    }

    public function ban(): static
    {
        return $this->state(['action' => ModerationAction::Ban]);
    }

    public function lifted(): static
    {
        return $this->state(fn (array $attributes): array => ['lifted_at' => now(), 'lifted_by_pubkey' => $attributes['actor_pubkey']]);
    }
}
