<?php

namespace App\Support\Cards;

use App\Models\User;
use App\Support\Nostr\Blockpile;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A GD drawing surface for the server-rendered images (share cards, badge
 * artwork), with the text and raster helpers of the invite card
 * (App\Support\Invites\InviteCard): drawn at twice the size and scaled down,
 * which smooths the polygon edges GD does not antialias. Coordinates and
 * sizes are given at 1x.
 *
 * Only assets the league may publish: the bundled OG fonts (Unbounded,
 * JetBrains Mono, OFL, licences in resources/fonts/og), the TWENTY ONE mark,
 * a player's own upload or the Blockpile avatar. No remote URL is fetched.
 */
final class Canvas
{
    public const INK = '#FFFFFF';

    public const INK_2 = '#ADADB0';

    public const INK_3 = '#8B8B90';

    public const GROUND = '#0A0A0B';

    public const ORANGE = '#F7931A';

    public GdImage $image;

    public function __construct(public readonly int $width, public readonly int $height, public readonly int $scale = 2, string $ground = self::GROUND)
    {
        $image = imagecreatetruecolor(max(1, $width * $scale), max(1, $height * $scale));

        if ($image === false) {
            throw new RuntimeException('GD could not create the image.');
        }

        $this->image = $image;
        imagealphablending($this->image, true);
        imagefill($this->image, 0, 0, $this->color($ground));
    }

    /** The PNG at its real size. */
    public function png(): string
    {
        $out = imagecreatetruecolor(max(1, $this->width), max(1, $this->height));
        imagecopyresampled($out, $this->image, 0, 0, 0, 0, $this->width, $this->height, $this->width * $this->scale, $this->height * $this->scale);

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    /* ---------- Shapes ------------------------------------------------------------------------------------------- */

    public function rect(int|float $x, int|float $y, int|float $w, int|float $h, string $color): void
    {
        $s = $this->scale;
        imagefilledrectangle($this->image, (int) round($x * $s), (int) round($y * $s), (int) round(($x + $w) * $s) - 1, (int) round(($y + $h) * $s) - 1, $this->color($color));
    }

    /**
     * @param  list<int|float>  $points  at 1x
     */
    public function polygon(array $points, string $color): void
    {
        imagefilledpolygon($this->image, array_map(fn (int|float $v): int => (int) round($v * $this->scale), $points), $this->color($color));
    }

    /**
     * The rank cube of the design's rank badge (FIXPASS.md section 5): a
     * hexagon in the tier colour with a lighter top face; provisional is the
     * outline only.
     */
    public function rankCube(string $colour, int|float $x, int|float $y, int|float $size, bool $outline = false): void
    {
        $s = $this->scale;
        [$cx, $cy, $r] = [($x + $size / 2) * $s, ($y + $size / 2) * $s, $size / 2 * $s];
        $hex = [$cx, $cy - $r, $cx + $r * 0.9, $cy - $r / 2, $cx + $r * 0.9, $cy + $r / 2, $cx, $cy + $r, $cx - $r * 0.9, $cy + $r / 2, $cx - $r * 0.9, $cy - $r / 2];
        $ints = array_map(fn (float $v): int => (int) round($v), $hex);

        if ($outline) {
            imagesetthickness($this->image, (int) max(2, round($size * $s / 18)));
            imagepolygon($this->image, $ints, $this->color($colour));
            imagesetthickness($this->image, 1);
        } else {
            imagefilledpolygon($this->image, $ints, $this->color($colour));
            // Right face darker, top face lighter.
            imagefilledpolygon($this->image, array_map(fn (float $v): int => (int) round($v), [$cx, $cy, $cx + $r * 0.9, $cy - $r / 2, $cx + $r * 0.9, $cy + $r / 2, $cx, $cy + $r]), $this->color($this->mix($colour, '#000000', 0.22)));
        }

        imagefilledpolygon($this->image, array_map(fn (float $v): int => (int) round($v), [$cx, $cy - $r, $cx + $r * 0.9, $cy - $r / 2, $cx, $cy, $cx - $r * 0.9, $cy - $r / 2]), $this->color($outline ? '#5C5C60' : $this->mix($colour, '#FFFFFF', 0.35)));
    }

    /**
     * An orange block with a darker side and top, as in the design's
     * "Block mined" card.
     */
    public function block(int|float $x, int|float $y, int|float $size, string $face = self::ORANGE): void
    {
        $depth = $size * 0.08;
        $this->polygon([$x, $y, $x + $depth, $y - $depth, $x + $size + $depth, $y - $depth, $x + $size, $y], $this->mix($face, '#FFFFFF', 0.25));
        $this->polygon([$x + $size, $y, $x + $size + $depth, $y - $depth, $x + $size + $depth, $y + $size - $depth, $x + $size, $y + $size], $this->mix($face, '#000000', 0.3));
        $this->rect($x, $y, $size, $size, $face);
    }

    /* ---------- Text --------------------------------------------------------------------------------------------- */

    public function text(string $text, string $face, int $px, int|float $x, int|float $baseline, string $color): void
    {
        imagettftext($this->image, $this->pt($px) * $this->scale, 0, (int) round($x * $this->scale), (int) round($baseline * $this->scale), $this->color($color), $this->font($face), $this->printable($text));
    }

    /** Text right-aligned at $right. */
    public function textRight(string $text, string $face, int $px, int|float $right, int|float $baseline, string $color): void
    {
        $this->text($text, $face, $px, $right - $this->width($text, $face, $px), $baseline, $color);
    }

    public function width(string $text, string $face, int $px): int
    {
        $box = imagettfbbox($this->pt($px), 0, $this->font($face), $this->printable($text)) ?: [0, 0, 0, 0, 0, 0, 0, 0];

        return (int) ceil($box[2] - $box[0]);
    }

    /** Cut with an ellipsis to fit. */
    public function fit(string $text, string $face, int $px, int $max): string
    {
        $text = $this->printable($text);

        if ($this->width($text, $face, $px) <= $max) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->width($text.'…', $face, $px) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /**
     * The largest of $sizes at which the text fits $max on one line (the smallest cuts).
     *
     * @param  list<int>  $sizes  largest first
     */
    public function fitSize(string $text, string $face, array $sizes, int $max): int
    {
        foreach ($sizes as $size) {
            if ($this->width($text, $face, $size) <= $max) {
                return $size;
            }
        }

        return (int) (end($sizes) ?: 16);
    }

    /**
     * @return list<string>
     */
    public function wrap(string $text, string $face, int $px, int $max): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', $this->printable($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;

            if ($line !== '' && $this->width($try, $face, $px) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return array_map(fn (string $line): string => $this->fit($line, $face, $px, $max), $lines);
    }

    /**
     * Wrapped lines, at most $maxLines; the last one cut with an ellipsis.
     *
     * @return list<string>
     */
    public function lines(string $text, string $face, int $px, int $max, int $maxLines): array
    {
        $lines = $this->wrap($text, $face, $px, $max);

        if (count($lines) <= $maxLines) {
            return $lines;
        }

        $kept = array_slice($lines, 0, $maxLines);
        $kept[$maxLines - 1] = $this->fit($kept[$maxLines - 1].' '.implode(' ', array_slice($lines, $maxLines)), $face, $px, $max);

        return array_values($kept);
    }

    /**
     * Draw wrapped text; returns the baseline after the last line.
     */
    public function paragraph(string $text, string $face, int $px, int|float $x, int|float $baseline, int $max, int $maxLines, string $color, float $leading = 1.35): float
    {
        foreach ($this->lines($text, $face, $px, $max, $maxLines) as $line) {
            $this->text($line, $face, $px, $x, $baseline, $color);
            $baseline += $px * $leading;
        }

        return $baseline;
    }

    /**
     * Only characters the bundled font subsets draw (Latin, Latin-1, Latin
     * Extended A/B, punctuation): anything else, emoji included, is dropped
     * rather than drawn as empty boxes.
     */
    public function printable(string $text): string
    {
        $clean = (string) preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{024F}\x{2010}-\x{2027}\x{2030}-\x{203A}\x{20AC}\x{2190}-\x{2193}\x{2212}]/u', '', $text);

        return trim((string) preg_replace('/\s{2,}/u', ' ', $clean));
    }

    private function font(string $face): string
    {
        return resource_path('fonts/og/'.match ($face) {
            'display' => 'Unbounded-Bold.ttf',
            'mono-bold' => 'JetBrainsMono-Bold.ttf',
            default => 'JetBrainsMono-Regular.ttf',
        });
    }

    /** GD sizes text in points at 96 dpi. */
    private function pt(int|float $px): float
    {
        return $px * 0.75;
    }

    /* ---------- Pictures ----------------------------------------------------------------------------------------- */

    /**
     * The player's uploaded avatar, else the Blockpile avatar of the key.
     */
    public function avatar(User $user, int|float $x, int|float $y, int $size): void
    {
        $radius = (int) round($size * 0.1);

        if ($user->avatar_path !== null && Storage::disk('public')->exists($user->avatar_path)) {
            $this->picture(Storage::disk('public')->path($user->avatar_path), $x, $y, $size, $radius);

            return;
        }

        $scale = $size * $this->scale / 96;
        $tile = imagecreatetruecolor($this->pixels($size), $this->pixels($size));

        foreach (Blockpile::polygons($user->pubkey) as $shape) {
            $points = array_map(fn (int $value): int => (int) round($value * $scale), $shape['points']);
            imagefilledpolygon($tile, $points, $this->colorOn($tile, $shape['fill']));
        }

        $this->paste($tile, $x, $y, $size, $radius);
    }

    /** The TWENTY ONE mark, the wordmark and the site's host on the right. */
    public function footer(int|float $x, int|float $y, int $tile, int|float $right): void
    {
        $this->picture(public_path('icon-512.png'), $x, $y, $tile, (int) round($tile * 0.18));
        $baseline = $y + $tile * 0.72;
        $wordX = $x + $tile + 16;
        $wordSize = (int) round($tile * 0.5);
        $this->text('TWENTY ONE', 'display', $wordSize, $wordX, $baseline, self::INK);
        $this->text('esports', 'mono', (int) round($tile * 0.34), $wordX + $this->width('TWENTY ONE', 'display', $wordSize) + 10, $baseline, self::INK_2);

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->textRight($host, 'mono', (int) round($tile * 0.36), $right, $baseline, self::INK_2);
    }

    public function picture(string $path, int|float $x, int|float $y, int $size, int $radius): void
    {
        $bytes = @file_get_contents($path);
        $source = $bytes === false ? false : @imagecreatefromstring($bytes);

        if ($source === false) {
            return;
        }

        $side = min(imagesx($source), imagesy($source));
        $px = $this->pixels($size);
        $tile = imagecreatetruecolor($px, $px);
        imagefill($tile, 0, 0, $this->colorOn($tile, self::GROUND));
        imagealphablending($tile, true);
        imagecopyresampled($tile, $source, 0, 0, (int) ((imagesx($source) - $side) / 2), (int) ((imagesy($source) - $side) / 2), $px, $px, $side, $side);

        $this->paste($tile, $x, $y, $size, $radius);
    }

    /**
     * Copy a tile onto the image with rounded corners (corners filled with
     * the ground colour).
     */
    private function paste(GdImage $tile, int|float $x, int|float $y, int $size, int $radius): void
    {
        $px = imagesx($tile);
        $r = (int) round($radius * $this->scale);
        $ground = $this->colorOn($tile, self::GROUND);

        if ($r > 0) {
            foreach ([[0, 0, $r, $r], [$px - 1, 0, $px - 1 - $r, $r], [0, $px - 1, $r, $px - 1 - $r], [$px - 1, $px - 1, $px - 1 - $r, $px - 1 - $r]] as [$cornerX, $cornerY, $centerX, $centerY]) {
                for ($i = min($cornerX, $centerX); $i <= max($cornerX, $centerX); $i++) {
                    for ($j = min($cornerY, $centerY); $j <= max($cornerY, $centerY); $j++) {
                        if (($i - $centerX) ** 2 + ($j - $centerY) ** 2 > $r ** 2) {
                            imagesetpixel($tile, $i, $j, $ground);
                        }
                    }
                }
            }
        }

        imagecopy($this->image, $tile, (int) round($x * $this->scale), (int) round($y * $this->scale), 0, 0, $px, $px);
    }

    /**
     * @return int<1, max>
     */
    private function pixels(int $size): int
    {
        return max(1, (int) round($size * $this->scale));
    }

    public function color(string $hex): int
    {
        return $this->colorOn($this->image, $hex);
    }

    private function colorOn(GdImage $image, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [0, 0, 0];
        $channel = fn (mixed $value): int => max(0, min(255, (int) $value));

        return (int) imagecolorallocate($image, $channel($r), $channel($g), $channel($b));
    }

    public function mix(string $from, string $to, float $t): string
    {
        $a = sscanf($from, '#%02x%02x%02x') ?: [0, 0, 0];
        $b = sscanf($to, '#%02x%02x%02x') ?: [0, 0, 0];

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $i): int => (int) round($a[$i] + ($b[$i] - $a[$i]) * max(0, min(1, $t))), [0, 1, 2]));
    }
}
