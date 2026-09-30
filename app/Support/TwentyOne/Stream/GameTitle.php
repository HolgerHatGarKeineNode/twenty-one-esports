<?php

namespace App\Support\TwentyOne\Stream;

use App\Games\GameRegistry;
use App\Support\Badges\BadgeCopy;

/**
 * A game's name as the stream prints it: the registry's name without an
 * edition after a colon ("Age of Empires II: Definitive Edition" is "Age of
 * Empires II" on a slide, where a 1280 px frame has no room for the
 * edition and every line that names the game cut it off mid-word). A name
 * without a colon ("EA Sports FC 26", "Nine Men's Morris") stays as it is;
 * a game no longer registered keeps its slug, as GameRegistry::name().
 */
final class GameTitle
{
    public static function of(string $slug): string
    {
        return self::short(app(GameRegistry::class)->name($slug));
    }

    public static function short(string $name): string
    {
        $title = trim(explode(': ', $name, 2)[0]);

        return $title === '' ? $name : $title;
    }

    /**
     * A ladder's name (BadgeCopy::ladder(): "Age of Empires II: Definitive
     * Edition 1v1") with the game's short title in it ("Age of Empires II 1v1").
     */
    public static function ladder(string $game, string $mode): string
    {
        $name = app(GameRegistry::class)->name($game);

        return str_replace($name, self::short($name), BadgeCopy::ladder($game, $mode));
    }
}
