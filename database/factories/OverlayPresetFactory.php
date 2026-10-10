<?php

namespace Database\Factories;

use App\Enums\OverlayVariant;
use App\Models\OverlayPreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A league live overlay in German with every default module. Its token is not known: a test that opens the URL
 * sets one with `withToken()`.
 *
 * @extends Factory<OverlayPreset>
 */
class OverlayPresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Laptop stream',
            'variant' => OverlayVariant::LeagueLive,
            'tournament_id' => null,
            'locale' => 'de',
            'modules' => OverlayPreset::MODULES,
            'token_hash' => OverlayPreset::hashToken(OverlayPreset::newToken()),
        ];
    }

    /** The preset answers to `$token`. */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => OverlayPreset::hashToken($token)]);
    }
}
