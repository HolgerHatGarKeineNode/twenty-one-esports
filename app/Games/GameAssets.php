<?php

namespace App\Games;

/**
 * How a game shows up: icon (x-icon name), colour tokens from app.css and
 * its cover art (<x-game-cover>), and the credit line shown under its name
 * (<x-game-credit>, plan "Blockli", P3): "by DerCaddy" for a game a
 * community developer made, with a link only when `creditUrl` is set.
 */
final readonly class GameAssets
{
    public function __construct(
        public string $icon,
        public string $colour,
        public string $colourDeep,
        public string $shortLabel,
        public ?GameCover $cover = null,
        public ?string $credit = null,
        public ?string $creditUrl = null,
    ) {}
}
