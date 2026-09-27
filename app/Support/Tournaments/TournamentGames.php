<?php

namespace App\Support\Tournaments;

use App\Games\GameRegistry;
use InvalidArgumentException;

/**
 * The games and modes a tournament can be played in: every mode of the game
 * registry that has a tournament profile ({@see GameProfile::for}). A game
 * added to `config('esports.games')` with a profile shows up in the format
 * chooser without touching it. Keys are the profile keys (`blitz`, `rl3`, …).
 */
final class TournamentGames
{
    /**
     * @return array<string, array{0: string, 1: string}> profile key => [game, mode], in registry order
     */
    public static function all(): array
    {
        $games = [];

        foreach (app(GameRegistry::class)->all() as $game) {
            foreach ($game->modes() as $mode) {
                try {
                    $games[GameProfile::for($game->slug(), $mode->slug)->key] = [$game->slug(), $mode->slug];
                } catch (InvalidArgumentException) {
                    // A mode without a tournament profile is not offered.
                }
            }
        }

        return $games;
    }

    /**
     * The chooser's rows: one per game, its modes as [key, label].
     *
     * @return list<array{slug: string, name: string, options: list<array{0: string, 1: string}>}>
     */
    public static function grouped(): array
    {
        $registry = app(GameRegistry::class);
        $rows = [];

        foreach (self::all() as $key => [$game, $mode]) {
            $rows[$game] ??= ['slug' => $game, 'name' => __($registry->get($game)->name()), 'options' => []];
            $rows[$game]['options'][] = [$key, __((string) $registry->mode($game, $mode)?->name)];
        }

        return array_values($rows);
    }

    /**
     * @return array{0: string, 1: string}|null [game, mode]
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keyOf(string $game, string $mode): ?string
    {
        $key = array_search([$game, $mode], self::all(), true);

        return is_string($key) ? $key : null;
    }
}
