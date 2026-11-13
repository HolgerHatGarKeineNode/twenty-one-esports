<?php

namespace App\Support\Cards;

/**
 * The picture of a combined tournament (DerCaddy, 2026-11-12: "als Vorschaubild eine Kombination aus den Bildern
 * beider Spiele"): the covers of its games as cards laid over one another, from the top left to the bottom right,
 * each one whole and on top of the ones before. A cover's name stands in its top left; every later card starts below
 * that name or right of it, so every name stays readable. Two and three covers run down one diagonal; four and five
 * stand in two rows, as a diagonal of five whole names leaves each card 31 % of the box's width. The same layout on
 * the page (<x-game-cover :games>) and on its cards (Canvas::covers()), so a link preview looks like the page it opens.
 */
final class CoverStack
{
    /** A cover's width over its height (the registry's covers are 16:9). */
    public const COVER_RATIO = 16 / 9;

    /** The most covers the picture draws. */
    public const MAX = 5;

    /** A card's width over a 16:9 box's on the diagonal, by the number of covers. */
    private const DIAGONAL = [2 => 0.62, 3 => 0.45];

    /** The highest a card may stand, over the box's height: room left for the next one's offset. */
    private const MAX_HEIGHT = 0.78;

    /**
     * How far down a cover's name reaches, over the cover's height. Measured on the art of the games a combined
     * tournament can play (2026-11-13): the lowest ends at 0.556 (Nine Men's Morris); the rest is room for the frame
     * of the card on top.
     */
    public const NAME_BOTTOM = 0.6;

    /** How far right a cover's name reaches, over the cover's width: the widest ends at 0.635 (checkers), plus the frame. */
    public const NAME_RIGHT = 0.66;

    /**
     * The cards of `count` covers in a box `ratio` times as wide as high, back to front: left edge and width as a
     * fraction of the box's width, top edge and height as a fraction of its height.
     *
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    public static function of(int $count, float $ratio = self::COVER_RATIO): array
    {
        $count = max(2, min(self::MAX, $count));
        // How much wider than a cover the box is: a card as high as the box is 1 / $wide of its width.
        $wide = $ratio / self::COVER_RATIO;

        return $count <= 3 ? self::diagonal($count, $wide) : self::rows($count, $wide);
    }

    /**
     * One diagonal: as wide as the table says, but small enough that each card starts below the name of the one
     * before or right of it.
     *
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    private static function diagonal(int $count, float $wide): array
    {
        $steps = $count - 1;
        $height = min(self::DIAGONAL[$count] * $wide, self::MAX_HEIGHT, max(1 / (1 + self::NAME_BOTTOM * $steps), $wide / (1 + self::NAME_RIGHT * $steps)));
        $width = $height / $wide;

        return array_map(fn (int $index): array => [
            'x' => $index * (1 - $width) / $steps,
            'y' => $index * (1 - $height) / $steps,
            'w' => $width,
            'h' => $height,
        ], range(0, $steps));
    }

    /**
     * Two rows, the upper one the longer: the lower row starts below the upper one's names, a card in a row right of
     * the name of the one before, and the last card ends in the bottom right.
     *
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    private static function rows(int $count, float $wide): array
    {
        $columns = intdiv($count + 1, 2);
        $height = min(1 / (1 + self::NAME_BOTTOM), $wide / (1 + self::NAME_RIGHT * ($columns - 1)));
        $width = $height / $wide;
        $step = (1 - $width) / ($columns - 1);

        return array_map(fn (int $index): array => [
            'x' => ($index < $columns ? $index : $index - $count + $columns) * $step,
            'y' => $index < $columns ? 0.0 : 1 - $height,
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
