<?php

namespace Database\Factories;

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A projected clan with its owner as captain. No signed events: tests that
 * need the Nostr side go through ClanService with a TestSigner.
 *
 * @extends Factory<Clan>
 */
class ClanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::limit(fake()->unique()->word().' '.fake()->word(), 40, '');

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(10, 99999),
            'owner_id' => User::factory(),
            'owner_pubkey' => fn (array $attributes) => User::query()->whereKey($attributes['owner_id'])->value('pubkey'),
            'name' => Str::title($name),
            'clantag' => strtoupper(fake()->unique()->bothify('?#??')),
            'description' => fake()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Clan $clan): void {
            ClanMember::query()->firstOrCreate(
                ['user_id' => $clan->owner_id],
                ['clan_id' => $clan->id, 'role' => ClanRole::Captain, 'joined_at' => now()],
            );
        });
    }
}
