<?php

namespace App\Games;

use App\Games\Contracts\PlayedOnOwnCopy;

/**
 * Rocket League: 1v1, 2v2, 3v3 lineups, best of 3 or 5, team goals per game
 * (validated as every series game, {@see SeriesGame}); `ot` marks overtime.
 */
final class RocketLeague extends SeriesGame implements PlayedOnOwnCopy
{
    /** Optional per-game flags (`score` tag, position 5+). */
    public const FLAGS = ['ot'];

    public function slug(): string
    {
        return 'rocket-league';
    }

    public function name(): string
    {
        return 'Rocket League';
    }

    public function modes(): array
    {
        $modes = [];

        foreach ([1, 2, 3] as $size) {
            $slug = "{$size}v{$size}";
            // 1v1 is a player ladder (NIP rev. 7.1): one rating per player, a clan's 1v1 lineup is context.
            $modes[$slug] = new GameMode($slug, $slug, $size, [3, 5], [], $size === 1 ? 'player' : 'lineup', false);
        }

        return $modes;
    }

    public function flags(): array
    {
        return self::FLAGS;
    }

    /**
     * Checked 2026-10-03. Free to play since 2020 on the Epic Games Store,
     * PlayStation, Xbox and Nintendo Switch ("free-to-play" on
     * https://www.playstation.com/en-us/games/rocket-league/, price 0.00 on
     * https://www.nintendo.com/us/store/products/rocket-league-switch/; the
     * Epic slug `rocket-league` answers on Epic's product API). Not on
     * Steam: app 252950 has no price there any more, new players cannot
     * get it. Xbox stays unlinked, no Xbox page was checked.
     */
    public function stores(): array
    {
        return [
            new GameStore(StorePlatform::Epic, 'https://store.epicgames.com/en-US/p/rocket-league'),
            new GameStore(StorePlatform::PlayStation, 'https://www.playstation.com/en-us/games/rocket-league/'),
            new GameStore(StorePlatform::Xbox),
            new GameStore(StorePlatform::NintendoSwitch, 'https://www.nintendo.com/us/store/products/rocket-league-switch/'),
        ];
    }

    public function isFreeToPlay(): bool
    {
        return true;
    }

    public function assets(): GameAssets
    {
        // The supplied art is 460 × 215, cropped to 16:9 and never scaled up.
        return new GameAssets('rocket-league', 'var(--color-rl)', 'var(--color-rl-deep)', 'RL', new GameCover('rocket-league', [382]));
    }
}
