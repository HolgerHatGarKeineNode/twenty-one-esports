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
     * Set by the Browser group (tests/Pest.php): its players are returning ones, who have a Nostr profile and so
     * never see the welcome banner of a bare Google sign-in (resources/views/components/shell/profile-welcome.blade.php),
     * which would push every first-screen measurement down. The Feature suite keeps the bare default: the profile
     * cache and the banner tests start from a player without a profile. A test that wants either passes
     * `profile_event_at` itself.
     */
    public static bool $withProfile = false;

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
            'profile_event_at' => self::$withProfile ? now() : null,
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

    /**
     * "Looking to play" on for blitz chess: only such a player can be sent a
     * blitz invite (App\Support\Chess\ChessInvites::invite).
     */
    public function lookingToPlay(): static
    {
        return $this->state(fn (array $attributes) => ['looking_to_play' => 'chess/blitz']);
    }

    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_member' => true,
            'member_checked_at' => now(),
        ]);
    }
}
