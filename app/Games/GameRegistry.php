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
     * The display order every surface shows (user 2026-10-01: Blockfill third,
     * Nine Men's Morris and Checkers at the very end): the `first` slugs in
     * their order, then every other game as registered, then the `last` slugs.
     * A slug of a game that is not registered is skipped.
     *
     * @param  array<array-key, Game>  $games
     * @param  array<mixed>  $first  slugs from config
     * @param  array<mixed>  $last  slugs from config
     * @return list<Game>
     */
    public static function ordered(array $games, array $first, array $last): array
    {
        $games = array_values($games);
        $first = array_values(array_filter($first, is_string(...)));
        $last = array_values(array_filter($last, is_string(...)));
        $rank = function (Game $game) use ($first, $last): int {
            $head = array_search($game->slug(), $first, true);
            $tail = array_search($game->slug(), $last, true);

            return match (true) {
                $head !== false => $head,
                $tail !== false => 2000 + $tail,
                default => 1000,
            };
        };

        $indexed = array_map(fn (Game $game, int $index): array => [$rank($game), $index, $game], $games, array_keys($games));
        usort($indexed, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($indexed, 2);
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
     * Whether a series of this game is scored in goals (Rocket League, EA
     * Sports FC) or in games won only (Age of Empires II): the report form,
     * the dispute and every page word the result by this. A game no longer
     * registered keeps the goals wording its series were played with.
     */
    public function hasGoals(string $slug): bool
    {
        $game = $this->find($slug);

        return ! $game instanceof SeriesGame || $game->hasGoals();
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
     * The highscore and time attack games (plan "AoE2 und Trackmania", P4),
     * in display order. Registered only through `esports.score_games` (the
     * demo behind its own switch, off by default); every switch between
     * chess, the series and the board games leaves these out.
     *
     * @return array<string, ScoreGame>
     */
    public function scores(): array
    {
        return array_filter($this->games, fn (Game $game): bool => $game instanceof ScoreGame);
    }

    public function isScore(string $slug): bool
    {
        return $this->find($slug) instanceof ScoreGame;
    }

    /**
     * The games two sides play against each other: every kind but a score
     * game. Lists that pair players (match filters, hubs of open matches,
     * the mempool of played games) read these.
     *
     * @return array<string, Game>
     */
    public function versus(): array
    {
        return array_filter($this->games, fn (Game $game): bool => ! $game instanceof ScoreGame);
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
