<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The pubkey is 32 random bytes: fine for everything that does not sign.
     * Tests that sign use {@see withPubkey()} with a real key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pubkey = bin2hex(random_bytes(32));

        return [
            'pubkey' => $pubkey,
            'npub' => NostrKeys::hexToNpub($pubkey),
            'name' => fake()->userName(),
            'is_member' => false,
        ];
    }

    public function withPubkey(string $pubkey): static
    {
        return $this->state(fn (array $attributes) => [
            'pubkey' => $pubkey,
            'npub' => NostrKeys::hexToNpub($pubkey),
        ]);
    }

    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_member' => true,
            'member_checked_at' => now(),
        ]);
    }
}
