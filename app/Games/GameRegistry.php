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

    /**
     * The URL of the game's cover art, or null when the league has none: the
     * views then draw the game-name tile (pages/tournaments/partials/cover).
     *
     * Interim source: a file the league ships itself, public/images/games/<slug>.<ext>.
     * The covers in the registry (GameAssets) replace this lookup when they land.
     */
    public function cover(string $game): ?string
    {
        $path = $this->coverPath($game);

        return $path === null ? null : asset(substr($path, strlen(public_path()) + 1));
    }

    /**
     * The cover's file on disk (for the server-drawn share cards), or null.
     */
    public function coverPath(string $game): ?string
    {
        if ($this->find($game) === null) {
            return null;
        }

        foreach (['webp', 'jpg', 'png'] as $extension) {
            $path = public_path("images/games/{$game}.{$extension}");

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
