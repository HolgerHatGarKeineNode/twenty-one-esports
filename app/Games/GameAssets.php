<?php

namespace App\Games;

/**
 * How a game shows up: icon (x-icon name), colour tokens from app.css and
 * its cover art (<x-game-cover>).
 */
final readonly class GameAssets
{
    public function __construct(
        public string $icon,
        public string $colour,
        public string $colourDeep,
        public string $shortLabel,
        public ?GameCover $cover = null,
    ) {}
}
