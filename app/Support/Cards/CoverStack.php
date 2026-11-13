<?php

namespace App\Support\Cards;

/**
 * The picture of a combined tournament (DerCaddy, 2026-11-12: "als Vorschaubild eine Kombination aus den Bildern
 * beider Spiele"): the covers of its games as cards laid over one another, from the top left to the bottom right,
 * each one whole and on top of the one before. A cover's name stands at its top left and the next card covers only
 * the previous one's bottom right, so every name stays readable. The same layout on the page
 * (<x-game-cover :games>) and on its cards (Canvas::covers()), so a link preview looks like the page it opens.
 */
final class CoverStack
{
    /** A cover's width over its height (the registry's covers are 16:9). */
    public const COVER_RATIO = 16 / 9;

    /** A card's width over the box's, by the number of covers: smaller for more, so each still shows its name. */
    private const WIDTH = [2 => 0.62, 3 => 0.46, 4 => 0.4, 5 => 0.36];

    /** The highest a card may stand, over the box's height: room left for the next one's offset. */
    private const MAX_HEIGHT = 0.78;

    /** How far down a cover's name reaches, over the cover's height: the next card starts lower. */
    public const NAME = 0.4;

    /**
     * The cards of `count` covers in a box `ratio` times as wide as high, back to front: left edge and width as a
     * fraction of the box's width, top edge and height as a fraction of its height.
     *
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    public static function of(int $count, float $ratio = self::COVER_RATIO): array
    {
        $count = max(2, min(5, $count));
        // As wide as the table says, but low enough that each card starts below the name of the one before.
        $height = min(self::WIDTH[$count] * $ratio / self::COVER_RATIO, self::MAX_HEIGHT, 1 / (1 + self::NAME * ($count - 1)));
        $width = $height * self::COVER_RATIO / $ratio;

        return array_map(fn (int $index): array => [
            'x' => $index * (1 - $width) / ($count - 1),
            'y' => $index * (1 - $height) / ($count - 1),
            'w' => $width,
            'h' => $height,
        ], range(0, $count - 1));
    }

    /** The CSS of card `index` of `count` in a 16:9 box (its height follows from its width and the cover's ratio). */
    public static function style(int $index, int $count): string
    {
        $cards = self::of($count);
        // of() draws five cards at most: a sixth game and on share the last card's place instead of failing.
        $card = $cards[min($index, count($cards) - 1)];
        $percent = fn (float $fraction): string => round($fraction * 100, 3).'%';

        return 'left:'.$percent($card['x']).';top:'.$percent($card['y']).';width:'.$percent($card['w']);
    }
}
