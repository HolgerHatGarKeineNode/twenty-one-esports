<?php

namespace Tests\Support;

use App\Games\Contracts\Game;
use App\Games\Contracts\Grouped;
use App\Games\GameAssets;
use App\Games\GameGroup;
use App\Games\GameKind;
use App\Games\GameMode;

/**
 * A made-up member of a made-up group for tests/Feature/GameSurfacesTest.php: two of them ("Fake Duo" A and B) are
 * registered beside the real games, so the test proves that any group of games (App\Games\Contracts\Grouped) is one
 * entry wherever games are listed, not only Go. Otherwise a FakeGame.
 */
final class FakeGroupedGame implements Game, Grouped
{
    public const GROUP = 'fake-duo';

    private FakeGame $game;

    public function __construct(private string $label)
    {
        $this->game = new FakeGame(self::GROUP.'-'.strtolower($label), 'Fake Duo '.$label);
    }

    public function group(): GameGroup
    {
        return new GameGroup(self::GROUP, 'Fake Duo');
    }

    public function groupLabel(): string
    {
        return $this->label;
    }

    public function slug(): string
    {
        return $this->game->slug();
    }

    public function name(): string
    {
        return $this->game->name();
    }

    public function kind(): GameKind
    {
        return $this->game->kind();
    }

    public function modes(): array
    {
        return $this->game->modes();
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->game->mode($slug);
    }

    public function resultSchema(GameMode $mode): array
    {
        return $this->game->resultSchema($mode);
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return $this->game->validateResult($mode, $result);
    }

    public function assets(): GameAssets
    {
        return $this->game->assets();
    }
}
