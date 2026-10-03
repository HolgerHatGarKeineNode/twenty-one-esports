<?php

namespace App\Games;

final class EaSportsFc27 extends EaSportsFc
{
    protected function edition(): int
    {
        return 27;
    }

    /**
     * Checked 2026-10-03: the platforms are the buy buttons of the official
     * page https://www.ea.com/games/ea-sports-fc/fc-27 (Epic Games, Nintendo
     * Switch 2, PlayStation, Steam, Xbox). Linked are only the pages checked
     * for the standard game (Steam app 4080220, out 24 Sep 2026; Nintendo's
     * Switch page); the PlayStation, Xbox and Epic buttons land on the
     * Ultimate Edition pre-order, so those stay unlinked.
     */
    public function stores(): array
    {
        return [
            new GameStore(StorePlatform::PlayStation),
            new GameStore(StorePlatform::Xbox),
            new GameStore(StorePlatform::Steam, 'https://store.steampowered.com/app/4080220/'),
            new GameStore(StorePlatform::Epic),
            new GameStore(StorePlatform::NintendoSwitch, 'https://www.nintendo.com/us/store/products/ea-sports-fc-27-switch/'),
        ];
    }
}
