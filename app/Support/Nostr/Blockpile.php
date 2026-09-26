<?php

namespace App\Support\Nostr;

use InvalidArgumentException;

/**
 * "Blockpile", the generated avatar of a player without a Nostr picture
 * (GeneratedAvatar.dc.html): a pile of isometric cubes drawn from the public
 * key. Same key, same pile, on every screen; no outside service.
 *
 * The palette table below IS the spec (copied verbatim from the design);
 * the output must equal the reference implementation byte for byte
 * (tests/Fixtures/blockpile-vectors.json).
 */
final class Blockpile
{
    /**
     * name, ground, ghost, light, mid, dark
     *
     * @var list<array{string, string, string, string, string, string}>
     */
    private const PALETTE = [
        ['Rosewood', '#261C1C', '#3C2F2F', '#CFB4B4', '#AB7C7C', '#835454'],
        ['Sand', '#26221C', '#3C372F', '#CFC4B4', '#AB987C', '#836F54'],
        ['Olive', '#25261C', '#3A3C2F', '#CBCFB4', '#A4AB7C', '#7B8354'],
        ['Moss', '#1D261C', '#303C2F', '#B7CFB4', '#80AB7C', '#578354'],
        ['Sage', '#1C2623', '#2F3C38', '#B4CFC6', '#7CAB9C', '#548373'],
        ['Lichen', '#1C2426', '#2F393C', '#B4C9CF', '#7CA0AB', '#547783'],
        ['Slate', '#1C1E26', '#2F323C', '#B4BBCF', '#7C88AB', '#545F83'],
        ['Mauve', '#261C24', '#3C2F39', '#CFB4C9', '#AB7CA0', '#835477'],
    ];

    /** First unique-slot index of each row (rows hold 1..6 cubes, mirrored). */
    private const ROW_OFFSET = [0, 1, 2, 4, 6, 9];

    /**
     * The SVG document for a 64-character hex public key.
     */
    public static function svg(string $pubkey): string
    {
        [$palette, $mask, $swap] = self::parameters($pubkey);
        [, $ground, $ghost, $light, $mid, $dark] = $palette;
        [$left, $right] = $swap ? [$dark, $mid] : [$mid, $dark];

        $out = [
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96">',
            sprintf('<rect width="96" height="96" fill="%s"/>', $ground),
        ];

        for ($r = 0; $r < 6; $r++) {
            for ($c = 0; $c <= $r; $c++) {
                $k = self::ROW_OFFSET[$r] + min($c, $r - $c);
                $x = 48 + (2 * $c - $r) * 7;
                $y = 18 + 12 * $r;

                if (($mask >> $k) & 1) {
                    $out[] = sprintf('<path d="M%d %dl7 4-7 4-7-4z" fill="%s"/>', $x, $y - 8, $light);
                    $out[] = sprintf('<path d="M%d %dl7 4v8l-7-4z" fill="%s"/>', $x - 7, $y - 4, $left);
                    $out[] = sprintf('<path d="M%d %dl7-4v8l-7 4z" fill="%s"/>', $x, $y, $right);
                } else {
                    $out[] = sprintf('<path d="M%d %dl7 4v8l-7 4-7-4v-8z" fill="%s"/>', $x, $y - 8, $ghost);
                }
            }
        }

        $out[] = '</svg>';

        return implode("\n", $out)."\n";
    }

    /**
     * The same drawing as {@see svg()} as absolute polygons in the 96 × 96
     * box, for raster output (the invite preview cards, P6b). The first entry
     * is the ground.
     *
     * @return list<array{points: list<int>, fill: string}>
     */
    public static function polygons(string $pubkey): array
    {
        [$palette, $mask, $swap] = self::parameters($pubkey);
        [, $ground, $ghost, $light, $mid, $dark] = $palette;
        [$left, $right] = $swap ? [$dark, $mid] : [$mid, $dark];

        $shapes = [['points' => self::points(0, 0, 96, 0, 96, 96, 0, 96), 'fill' => $ground]];

        for ($r = 0; $r < 6; $r++) {
            for ($c = 0; $c <= $r; $c++) {
                $k = self::ROW_OFFSET[$r] + min($c, $r - $c);
                $x = 48 + (2 * $c - $r) * 7;
                $y = 18 + 12 * $r;

                if (($mask >> $k) & 1) {
                    $shapes[] = ['points' => self::points($x, $y - 8, $x + 7, $y - 4, $x, $y, $x - 7, $y - 4), 'fill' => $light];
                    $shapes[] = ['points' => self::points($x - 7, $y - 4, $x, $y, $x, $y + 8, $x - 7, $y + 4), 'fill' => $left];
                    $shapes[] = ['points' => self::points($x, $y, $x + 7, $y - 4, $x + 7, $y + 4, $x, $y + 8), 'fill' => $right];
                } else {
                    $shapes[] = ['points' => self::points($x, $y - 8, $x + 7, $y - 4, $x + 7, $y + 4, $x, $y + 8, $x - 7, $y + 4, $x - 7, $y - 4), 'fill' => $ghost];
                }
            }
        }

        return $shapes;
    }

    /**
     * x, y pairs of one polygon.
     *
     * @return list<int>
     */
    private static function points(int ...$coordinates): array
    {
        // array_values: a variadic also takes named arguments, which would be string keys.
        return array_values($coordinates);
    }

    /**
     * Palette name, 12-bit mask and light direction, for tests and the spec table.
     *
     * @return array{palette: string, mask: int, swap: bool}
     */
    public static function describe(string $pubkey): array
    {
        [$palette, $mask, $swap] = self::parameters($pubkey);

        return ['palette' => $palette[0], 'mask' => $mask, 'swap' => $swap];
    }

    /**
     * The pile's ground colour, e.g. for a banner placeholder in the same hue.
     *
     * @return array{ground: string, ghost: string}
     */
    public static function colors(string $pubkey): array
    {
        [$palette] = self::parameters($pubkey);

        return ['ground' => $palette[1], 'ghost' => $palette[2]];
    }

    /**
     * @return array{array{string, string, string, string, string, string}, int, bool}
     */
    private static function parameters(string $pubkey): array
    {
        if (! NostrKeys::isHexPubkey($pubkey)) {
            throw new InvalidArgumentException('Blockpile needs a 64-character lowercase hex public key.');
        }

        $b = array_values(unpack('C4', (string) hex2bin(substr($pubkey, 0, 8))) ?: []);

        $mask = (($b[1] << 8) | $b[2]) & 0xFFF;

        if (substr_count(decbin($mask), '1') < 5) {
            $mask ^= 0xFFF;
        }

        return [self::PALETTE[$b[0] % 8], $mask, $b[3] % 2 === 1];
    }
}
