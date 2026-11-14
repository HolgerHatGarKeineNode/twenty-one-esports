<?php

namespace App\Support\Cards;

use GdImage;

/**
 * Go's board as a card draws it: the material of the board on the game's page (resources/js/boardGame.js: `GO`,
 * `STONE_EDGE`, `STONE_GRADIENTS`, drawGoBoard()), so a link preview shows the board the link opens. A slab of
 * honey-coloured wood with its straight grain and its darker lower face, black lines, and stones lit from the upper
 * left with their shadow on the wood. Orange is never a stone: it is the ring on the stone set last.
 * The values here are the page's; tests/Feature/GoGameTest.php holds both files to the same ones.
 */
final class StoneBoardArt
{
    /** The slab's upper face, from its upper to its lower edge. */
    public const WOOD = ['#B07C3D', '#A47038'];

    /** The slab's lower face. */
    public const SIDE = '#553619';

    public const GRAIN = '#3C220A';

    /** The light on the slab's upper edge. */
    public const SHEEN = '#FFE2B4';

    public const LINE = '#2A1B0B';

    /** The ring on the stone set last, by the stone's colour (#F7931A has 2:1 on a white stone). */
    public const RING = ['black' => '#F7931A', 'white' => '#B9640A'];

    public const EDGE = ['black' => '#000000', 'white' => '#6B6354'];

    /** A stone's edge on the dark ground of the card, beside a player's name (the page's stone glyph). */
    public const RIM = ['black' => '#8B8B90', 'white' => '#6B6354'];

    public const SHADOW = '#1E0F02';

    /**
     * A stone's light: a radial gradient with its centre and radius as parts of the stone's box, and its stops.
     *
     * @var array<'black'|'white', array{cx: float, cy: float, r: float, stops: list<array{0: float, 1: string}>}>
     */
    public const STONE = [
        'black' => ['cx' => 0.35, 'cy' => 0.30, 'r' => 0.75, 'stops' => [[0.0, '#5C5C66'], [0.38, '#26262B'], [1.0, '#0B0B0D']]],
        'white' => ['cx' => 0.35, 'cy' => 0.30, 'r' => 0.80, 'stops' => [[0.0, '#FFFFFF'], [0.55, '#F4F1EA'], [1.0, '#CBC5B8']]],
    ];

    /** A stone's radius over the distance between two lines. */
    public const RADIUS = 0.47;

    /** @var array<string, GdImage> the stones drawn once a size, pasted for every point */
    private array $sprites = [];

    public function __construct(private readonly Canvas $c) {}

    /** A number from 0 to 1 that is the same for the same seed: the grain is the page's, streak for streak. */
    public static function grainAt(int $seed): float
    {
        $x = sin($seed * 12.9898) * 43758.5453;

        return $x - floor($x);
    }

    /**
     * The colour of a stone at a place of its box (0 to 1 from its left and its top).
     *
     * @param  'black'|'white'  $kind
     * @return array{0: int, 1: int, 2: int}
     */
    public static function shade(string $kind, float $fx, float $fy): array
    {
        $light = self::STONE[$kind];
        $t = min(1.0, hypot($fx - $light['cx'], $fy - $light['cy']) / $light['r']);
        $stops = $light['stops'];

        for ($i = 1; $i < count($stops); $i++) {
            if ($t <= $stops[$i][0]) {
                $from = self::rgb($stops[$i - 1][1]);
                $to = self::rgb($stops[$i][1]);
                // Two stops at the same place are a hard edge: the later colour from there on.
                $span = $stops[$i][0] - $stops[$i - 1][0];
                $part = $span > 0.0 ? ($t - $stops[$i - 1][0]) / $span : 1.0;

                return [(int) round($from[0] + ($to[0] - $from[0]) * $part), (int) round($from[1] + ($to[1] - $from[1]) * $part), (int) round($from[2] + ($to[2] - $from[2]) * $part)];
            }
        }

        return self::rgb($stops[count($stops) - 1][1]);
    }

    /**
     * The stones' light, their shadow and the wood as SVG gradients (`go-stone-black`, `go-stone-white`,
     * `go-stone-shadow`, `go-wood`), value for value the page's (resources/js/boardGame.js STONE_GRADIENTS): for a
     * drawing that is an SVG, as the stream's board scene.
     */
    public static function gradients(): string
    {
        $svg = '';

        foreach (self::STONE as $kind => $light) {
            $svg .= '<radialGradient id="go-stone-'.$kind.'" cx="'.($light['cx'] * 100).'%" cy="'.($light['cy'] * 100).'%" r="'.($light['r'] * 100).'%">';

            foreach ($light['stops'] as [$offset, $colour]) {
                $svg .= '<stop offset="'.$offset.'" stop-color="'.$colour.'"/>';
            }

            $svg .= '</radialGradient>';
        }

        return $svg
            .'<radialGradient id="go-stone-shadow"><stop offset="0.7" stop-color="'.self::SHADOW.'" stop-opacity="0.5"/><stop offset="1" stop-color="'.self::SHADOW.'" stop-opacity="0"/></radialGradient>'
            .'<linearGradient id="go-wood" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.self::WOOD[0].'"/><stop offset="1" stop-color="'.self::WOOD[1].'"/></linearGradient>';
    }

    /** A count's points as a player reads them: "44.5", "7,5" in German, "39". */
    public static function points(float $points): string
    {
        $text = rtrim(rtrim(number_format(abs($points), 1, '.', ''), '0'), '.');

        return app()->getLocale() === 'de' ? str_replace('.', ',', $text) : $text;
    }

    /**
     * A slab of the wood: the upper face `$w` by `$h` with the grain and the light on its edge, the lower face
     * `$face` high under it.
     */
    public function slab(int|float $x, int|float $y, int|float $w, int|float $h, int|float $face): void
    {
        $s = $this->c->scale;
        $image = $this->c->image;
        $this->c->rect($x, $y + $face, $w, $h, self::SIDE);
        [$left, $top, $right, $bottom] = [(int) round($x * $s), (int) round($y * $s), (int) round(($x + $w) * $s) - 1, (int) round(($y + $h) * $s) - 1];

        for ($row = $top; $row <= $bottom; $row++) {
            imageline($image, $left, $row, $right, $row, $this->c->color($this->c->mix(self::WOOD[0], self::WOOD[1], ($row - $top) / max(1, $bottom - $top))));
        }

        $width = $right - $left + 1;

        for ($streak = 1; $streak <= 28; $streak++) {
            $thick = (0.002 + self::grainAt($streak + 40) * 0.009) * $width;
            $at = $left + self::grainAt($streak) * ($width - $thick);
            imagefilledrectangle($image, (int) round($at), $top, (int) round($at + max(1.0, $thick)), $bottom, $this->alpha(self::GRAIN, 0.025 + self::grainAt($streak + 80) * 0.05));
        }

        imagefilledrectangle($image, $left, $top, $right, $top + max(0, (int) round($s / 2) - 1), $this->alpha(self::SHEEN, 0.4));
    }

    /**
     * A position on the slab, in a square `$size` wide with the slab's lower face inside it: the lines, the star
     * points and the stones of the rules' view (GoRules::view()), the stone set last with its ring.
     *
     * @param  array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, points: list<array{id: string, x: int, y: int}>, pieces: array<string, array{side: 'w'|'b', kind: string}>, stars?: list<string>}  $view
     */
    public function board(array $view, int|float $x, int|float $y, int|float $size, ?string $last = null): void
    {
        $face = round($size * 0.018);
        $top = $size - $face;
        $this->slab($x, $y, $size, $top, $face);
        $scale = $top / max(1, $view['width'], $view['height']);
        $ox = $x + ($size - $top) / 2;
        $xs = array_column($view['points'], 'x');
        $ys = array_column($view['points'], 'y');
        // A view without points has no lines to tell from the frame and no stones.
        [$near, $far, $high, $low] = $xs === [] || $ys === [] ? [0, 0, 0, 0] : [min($xs), max($xs), min($ys), max($ys)];
        $unit = count($view['points']) > 1 ? ($far - $near) / (sqrt(count($view['points'])) - 1) * $scale : $top;
        // The page's lines are 1 px on a board of about 560 px, the frame 1.8 px.
        $thin = max(1.0, $size / 400);

        foreach ($view['lines'] as [$x1, $y1, $x2, $y2]) {
            $edge = $x1 === $x2 ? $x1 === $near || $x1 === $far : $y1 === $high || $y1 === $low;
            $half = ($edge ? $thin * 1.8 : $thin) / 2;
            $this->c->rect($ox + $x1 * $scale - $half, $y + $y1 * $scale - $half, ($x2 - $x1) * $scale + 2 * $half, ($y2 - $y1) * $scale + 2 * $half, self::LINE);
        }

        $stars = $view['stars'] ?? [];
        $dot = $unit * (count($view['points']) > 81 ? 0.12 : 0.1);

        foreach ($view['points'] as $point) {
            if (in_array($point['id'], $stars, true)) {
                $this->disc($ox + $point['x'] * $scale, $y + $point['y'] * $scale, $dot, self::LINE);
            }
        }

        foreach ($view['points'] as $point) {
            $piece = $view['pieces'][$point['id']] ?? null;

            if ($piece !== null && in_array($piece['kind'], ['black', 'white'], true)) {
                $this->stone($piece['kind'], $ox + $point['x'] * $scale, $y + $point['y'] * $scale, $unit * self::RADIUS, $point['id'] === $last);
            }
        }
    }

    /**
     * One stone with its centre at `$cx`, `$cy`: lit from the upper left, with its edge, on the wood with its shadow,
     * the one set last with the ring.
     *
     * @param  'black'|'white'  $kind
     */
    public function stone(string $kind, int|float $cx, int|float $cy, int|float $radius, bool $ring = false, bool $shadow = true): void
    {
        $s = $this->c->scale;
        $r = max(2.0, $radius * $s);
        $key = $kind.'|'.round($r, 1).'|'.(int) $ring.'|'.(int) $shadow;
        $sprite = $this->sprites[$key] ??= $this->sprite($kind, $r, $ring, $shadow);
        $half = imagesx($sprite) / 2;
        imagecopy($this->c->image, $sprite, (int) round($cx * $s - $half), (int) round($cy * $s - $half), 0, 0, imagesx($sprite), imagesy($sprite));
    }

    /**
     * A stone beside a player's name on the card's dark ground: its light rim, then the stone without a shadow.
     *
     * @param  'black'|'white'  $kind
     */
    public function glyph(string $kind, int|float $cx, int|float $cy, int|float $radius): void
    {
        $this->disc($cx, $cy, $radius + 1.5, self::RIM[$kind]);
        $this->stone($kind, $cx, $cy, $radius, false, false);
    }

    private function disc(int|float $cx, int|float $cy, int|float $radius, string $colour): void
    {
        $s = $this->c->scale;
        $d = (int) round(2 * $radius * $s);
        imagefilledellipse($this->c->image, (int) round($cx * $s), (int) round($cy * $s), $d, $d, $this->c->color($colour));
    }

    /**
     * One stone as its own picture with a clear ground, every pixel from the stone's light, edge, ring and shadow.
     *
     * @param  'black'|'white'  $kind
     */
    private function sprite(string $kind, float $r, bool $ring, bool $shadow): GdImage
    {
        $unit = $r / self::RADIUS;
        $size = (int) ceil(2 * ($unit * 0.55 + $unit * 0.07) + 4);
        $size = max(2, $size + $size % 2);
        $sprite = imagecreatetruecolor($size, $size);
        imagealphablending($sprite, false);
        imagesavealpha($sprite, true);
        imagefill($sprite, 0, 0, (int) imagecolorallocatealpha($sprite, 0, 0, 0, 127));
        $centre = $size / 2;
        $edge = self::rgb(self::EDGE[$kind]);
        $dark = self::rgb(self::SHADOW);
        $band = self::rgb(self::RING[$kind]);

        for ($py = 0; $py < $size; $py++) {
            for ($px = 0; $px < $size; $px++) {
                [$dx, $dy] = [$px + 0.5 - $centre, $py + 0.5 - $centre];
                $d = hypot($dx, $dy);
                // The shadow under the stone, a little down and to the right: half as dark as its colour, fading from 0.7 of its radius.
                $fall = $shadow ? hypot($dx - $unit * 0.02, $dy - $unit * 0.07) / ($unit * 0.55) : 2.0;
                $under = $fall >= 1.0 ? 0.0 : 0.5 * min(1.0, (1.0 - $fall) / 0.3);
                $cover = max(0.0, min(1.0, $r - $d + 0.5));

                if ($cover <= 0.0 && $under <= 0.0) {
                    continue;
                }

                $colour = self::shade($kind, 0.5 + $dx / (2 * $r), 0.5 + $dy / (2 * $r));
                // The stone's edge, one pixel of the card: two of the drawing.
                $rim = max(0.0, min(1.0, $d - ($r - 2.0)));
                $colour = [$colour[0] + ($edge[0] - $colour[0]) * $rim, $colour[1] + ($edge[1] - $colour[1]) * $rim, $colour[2] + ($edge[2] - $colour[2]) * $rim];

                if ($ring) {
                    $on = max(0.0, min(1.0, $unit * 0.0375 - abs($d - $unit * 0.2) + 0.5));
                    $colour = [$colour[0] + ($band[0] - $colour[0]) * $on, $colour[1] + ($band[1] - $colour[1]) * $on, $colour[2] + ($band[2] - $colour[2]) * $on];
                }

                // The stone over its shadow.
                $alpha = $cover + $under * (1 - $cover);
                $mixed = array_map(fn (int $i): int => self::channel(($colour[$i] * $cover + $dark[$i] * $under * (1 - $cover)) / $alpha), [0, 1, 2]);
                imagesetpixel($sprite, $px, $py, (int) imagecolorallocatealpha($sprite, $mixed[0], $mixed[1], $mixed[2], self::clear($alpha)));
            }
        }

        return $sprite;
    }

    private function alpha(string $hex, float $opacity): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return (int) imagecolorallocatealpha($this->c->image, $r, $g, $b, self::clear($opacity));
    }

    /**
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private static function rgb(string $hex): array
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [0, 0, 0];

        return [self::channel((int) $r), self::channel((int) $g), self::channel((int) $b)];
    }

    /**
     * A colour's channel as GD takes it.
     *
     * @return int<0, 255>
     */
    private static function channel(int|float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /**
     * An opacity from 0 to 1 as GD's alpha: 0 covers, 127 is clear.
     *
     * @return int<0, 127>
     */
    private static function clear(float $opacity): int
    {
        return max(0, min(127, (int) round(127 * (1 - $opacity))));
    }
}
