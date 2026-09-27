<?php

namespace Tests\Support;

use App\Games\Contracts\Game;
use App\Games\GameAssets;
use App\Games\GameMode;
use App\Games\GameRegistry;

/**
 * A made-up game for scale tests of the shell navigation (the game hub with
 * a dozen games). Test-only: it is bound into the container by the test that
 * needs it, never registered in config/esports.php, so no live page ever
 * shows one. It has no cover (the views draw their stand-in) and no pages of
 * its own (GameNames::page() sends a non-series game to the chess lobby).
 */
final class FakeGame implements Game
{
    /**
     * @param  'player'|'lineup'  $rates
     */
    public function __construct(private string $slug, private string $name, private string $rates = 'player') {}

    /**
     * The registered games plus made-up ones, `$total` games in all.
     */
    public static function registry(int $total): GameRegistry
    {
        $real = array_values(app(GameRegistry::class)->all());
        $names = ['Counter-Strike 2', 'Street Fighter 6', 'Age of Empires II', 'Tekken 8', 'StarCraft II', 'Trackmania', 'Chess960', 'Brawlhalla', 'Dota 2', 'Valorant'];
        $fakes = [];

        foreach (array_slice($names, 0, max(0, $total - count($real))) as $index => $name) {
            $fakes[] = new self('fake-'.$index, $name, $index % 2 === 0 ? 'lineup' : 'player');
        }

        return new GameRegistry([...$real, ...$fakes]);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function modes(): array
    {
        return $this->rates === 'lineup'
            ? ['5v5' => new GameMode('5v5', '5v5', 5, [1, 3], [], 'lineup', false)]
            : ['1v1' => new GameMode('1v1', '1v1', 1, [3], [], 'player', false)];
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    public function resultSchema(GameMode $mode): array
    {
        return [];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return [];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('trophy', 'var(--color-edge)', 'var(--color-line)', mb_substr($this->name, 0, 4));
    }
}
