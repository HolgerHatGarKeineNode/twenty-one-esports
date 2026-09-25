<?php

namespace App\Games;

/**
 * How a game shows up: icon (x-icon name), colour tokens from app.css and an
 * optional logo. Logos are added in the imagery pass; until then `logo` is null
 * and views leave an <img> slot.
 */
final readonly class GameAssets
{
    public function __construct(
        public string $icon,
        public string $colour,
        public string $colourDeep,
        public string $shortLabel,
        public ?string $logo = null,
    ) {}
}
