<?php

namespace App\Support\Tournaments;

use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use InvalidArgumentException;

/**
 * The games and modes a tournament can be played in: every mode of the game
 * registry that has a tournament profile ({@see GameProfile::for}). A game
 * added to `config('esports.games')` with a profile shows up in the format
 * chooser without touching it. Keys are the profile keys (`blitz`, `rl3`, …).
 *
 * Never Blockfill (plan "Blockfill", P6): its only boards are the weeks the
 * league opens itself (BlockfillWeeks), which take its runs; a tournament an
 * organizer made of it would never get one.
 *
 * Hyperbitcoinization (plan "Hyperbitcoinization", P5c) only while
 * `esports.hyper.tournaments` is on (off by default, user 2026-10-09): off,
 * the chooser does not offer it ({@see grouped()}, {@see offers()}) and no
 * tournament is made of it; one that exists keeps its key ({@see all()}), so
 * its pages and edits still read its game.
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
            if ($game->slug() === Blockfill::SLUG) {
                continue;
            }

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
     * Whether organizers may make Hyperbitcoinization tournaments (`esports.hyper.tournaments`).
     */
    public static function hyperOffered(): bool
    {
        return (bool) config('esports.hyper.tournaments');
    }

    /**
     * Whether the chooser offers this profile key for a new tournament: a known key, and Hyperbitcoinization only
     * while its tournaments are switched on. `$current`: the key the tournament has now, which stays offered.
     */
    public static function offers(string $key, ?string $current = null): bool
    {
        $found = self::find($key);

        return $found !== null && ($key === $current || $found[0] !== Hyperbitcoinization::SLUG || self::hyperOffered());
    }

    /**
     * The chooser's rows: one per game, its modes as [key, label]; only what {@see offers()} (the key the
     * tournament has now, `$current`, included).
     *
     * @return list<array{slug: string, name: string, options: list<array{0: string, 1: string}>}>
     */
    public static function grouped(?string $current = null): array
    {
        $registry = app(GameRegistry::class);
        $rows = [];

        foreach (self::all() as $key => [$game, $mode]) {
            if (! self::offers($key, $current)) {
                continue;
            }

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
