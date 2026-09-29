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
     * The games played as best-of series (Rocket League, EA Sports FC), in
     * display order: challenges, lineups and the match room serve these.
     *
     * @return array<string, SeriesGame>
     */
    public function series(): array
    {
        return array_filter($this->games, fn (Game $game): bool => $game instanceof SeriesGame);
    }

    public function isSeries(string $slug): bool
    {
        return $this->find($slug) instanceof SeriesGame;
    }

    /**
     * The board games other than chess (nine men's morris, checkers), in
     * display order. Registered only while `esports.board_games` has them on;
     * every switch between chess and the series leaves these out until their
     * phase opens the feature.
     *
     * @return array<string, Game>
     */
    public function boards(): array
    {
        return array_filter($this->games, fn (Game $game): bool => $game->kind() === GameKind::Board);
    }

    public function isBoard(string $slug): bool
    {
        return $this->find($slug)?->kind() === GameKind::Board;
    }

    /**
     * Display name of a game; the slug itself for a game no longer registered.
     */
    public function name(string $slug): string
    {
        return $this->find($slug)?->name() ?? $slug;
    }

    /**
     * The cover art of a game, or null for an unknown game (views show a fallback).
     */
    public function cover(string $slug): ?GameCover
    {
        return $this->find($slug)?->assets()->cover;
    }

    /**
     * The largest cover file on disk as JPEG (GD reads it everywhere), for the
     * server-drawn share cards (ShareCard, the tournament invite card), or null
     * for an unknown game or a missing file (the card draws its stand-in).
     */
    public function coverPath(string $slug): ?string
    {
        $cover = $this->cover($slug);
        $path = $cover === null ? null : public_path($cover->path($cover->largest(), 'jpg'));

        return $path !== null && is_file($path) ? $path : null;
    }
}
