<?php

namespace App\Games;

/**
 * One side of a board game as the pages show it: what it is called and the colour of its square beside a player.
 * Every surface that names a side or draws its swatch reads this from the game (BoardGame::sides()), so the words and
 * the colours cannot drift apart: Go's first side is Black with a black swatch, Blockspace's is Orange with an orange one.
 */
final readonly class BoardSide
{
    /**
     * @param  string  $name  the side's name, a key of lang/de.json ("White", "Orange")
     * @param  'white'|'black'  $stone  the colour its pieces are drawn in where a board has only the two
     * @param  string  $swatch  the fill of the square beside the player, as a hex colour
     * @param  string  $swatchEdge  the square's edge, so a dark fill stands out on the dark ground
     * @param  string|null  $note  the note after a player's name, a key of lang/de.json ("(white)"); null puts the name in brackets
     */
    public function __construct(
        public string $name,
        public string $stone,
        public string $swatch,
        public string $swatchEdge,
        public ?string $note = null,
    ) {}

    public static function white(): self
    {
        return new self('White', 'white', '#F4F4F5', '#F4F4F5', '(white)');
    }

    public static function black(): self
    {
        return new self('Black', 'black', '#0A0A0B', '#5C5C60', '(black)');
    }
}
