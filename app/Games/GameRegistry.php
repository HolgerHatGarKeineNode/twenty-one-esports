<?php

namespace App\Games;

use App\Games\Contracts\Game;
use InvalidArgumentException;

/**
 * The games the league runs, from `config('esports.games')`. Bound as a
 * singleton in AppServiceProvider.
 */
final class GameRegistry
{
    /** @var array<string, Game> */
    private array $games = [];

    /**
     * @param  iterable<Game>  $games
     */
    public function __construct(iterable $games)
    {
        foreach ($games as $game) {
            if (isset($this->games[$game->slug()])) {
                throw new InvalidArgumentException("Game [{$game->slug()}] is registered twice.");
            }

            $this->games[$game->slug()] = $game;
        }
    }

    /**
     * @return array<string, Game>
     */
    public function all(): array
    {
        return $this->games;
    }

    public function find(string $slug): ?Game
    {
        return $this->games[$slug] ?? null;
    }

    public function get(string $slug): Game
    {
        return $this->find($slug) ?? throw new InvalidArgumentException("Unknown game [{$slug}].");
    }

    public function mode(string $game, string $mode): ?GameMode
    {
        return $this->find($game)?->mode($mode);
    }
}
