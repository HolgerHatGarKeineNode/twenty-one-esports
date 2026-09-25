<?php

namespace Database\Factories;

use App\Enums\InviteLinkType;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A one-time daily chess link, open for a day. States pick the other types
 * and the closed variants; tests that need the rules create links through
 * App\Support\Invites\InviteLinks instead.
 *
 * @extends Factory<InviteLink>
 */
class InviteLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => InviteLink::newCode(),
            'type' => InviteLinkType::Daily,
            'inviter_id' => User::factory(),
            'options' => ['color' => 'random'],
            'max_uses' => 1,
            'uses' => 0,
            'expires_at' => now()->addDay(),
        ];
    }

    public function blitz(): static
    {
        return $this->state(['type' => InviteLinkType::Blitz, 'options' => []]);
    }

    public function clan(?Clan $clan = null): static
    {
        return $this->state(fn () => [
            'type' => InviteLinkType::Clan,
            'clan_id' => $clan->id ?? Clan::factory(),
            'inviter_id' => fn (array $attributes) => Clan::query()->whereKey($attributes['clan_id'])->value('owner_id'),
            'options' => [],
            'max_uses' => null,
            'expires_at' => now()->addWeek(),
        ]);
    }

    public function multiUse(): static
    {
        return $this->state(['max_uses' => null]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
