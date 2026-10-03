<?php

namespace App\Games;

final class EaSportsFc26 extends EaSportsFc
{
    protected function edition(): int
    {
        return 26;
    }

    /** The supplied art is 480 × 270 and is never scaled up. */
    protected function coverWidths(): array
    {
        return [480];
    }

    /**
     * Checked 2026-10-03: the platforms are the buy buttons of the official
     * page https://www.ea.com/games/ea-sports-fc/fc-26 (Epic Games, Nintendo
     * Switch, PlayStation, Steam, Xbox); each URL is where its button
     * (go.ea.com/fc26-*-se) lands, the standard edition, and answered 200.
     */
    public function stores(): array
    {
        return [
            new GameStore(StorePlatform::PlayStation, 'https://store.playstation.com/en-us/product/UP0006-PPSA27360_00-26STANDARDBUNDLE/'),
            new GameStore(StorePlatform::Xbox, 'https://www.xbox.com/en-US/games/store/ea-sports-fc-26-standard-edition-xbox-one-xbox-series-x-s/9MXZBTLG26VX'),
            new GameStore(StorePlatform::Steam, 'https://store.steampowered.com/app/3405690/'),
            new GameStore(StorePlatform::Epic, 'https://store.epicgames.com/en-US/p/ea-sports-fc-26'),
            new GameStore(StorePlatform::NintendoSwitch, 'https://www.nintendo.com/us/store/products/ea-sports-fc-26-switch/'),
        ];
    }
}
