<?php

namespace App\Support\Invites;

use App\Enums\InviteLinkType;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\User;
use App\Support\Nostr\Blockpile;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The preview image of an invite link (ShareCards.dc.html "Invite cards"),
 * rendered on the server with GD so messengers get a PNG without running any
 * JavaScript: 1200 × 630 (`wide`, og:image) and 1080 × 1080 (`square`, for
 * chats that crop previews square).
 *
 * Only assets the league may publish: our own fonts (Unbounded and JetBrains
 * Mono under the OFL, a DejaVu subset for the chess glyphs, licences next to
 * the files in resources/fonts/og), the TWENTY ONE mark, the Blockpile avatar
 * or the player's own upload, and a board drawn here. A Nostr picture on a
 * foreign host is not fetched (no request from the server to a URL a player
 * chose), and Rocket League art stays out until its licence is cleared: the
 * series card shows the TWENTY ONE mark instead (plan, "Einladungs-Designs").
 *
 * Drawn at twice the size and scaled down, which smooths the polygon edges
 * GD does not antialias. Cached per link, format and drawn content: a new
 * name or a new picture makes a new file, an unchanged card is never redrawn.
 */
final class InviteCard
{
    public const FORMATS = ['wide' => [1200, 630], 'square' => [1080, 1080]];

    private const SCALE = 2;

    private const INK = '#FFFFFF';

    private const INK_2 = '#ADADB0';

    private const GROUND = '#0A0A0B';

    private GdImage $image;

    private float $s = self::SCALE;

    public function __construct(private InviteLink $link) {}

    /**
     * The PNG bytes, from the cache when the drawn content is unchanged.
     */
    public function png(string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new RuntimeException("Unknown invite card format {$format}.");
        }

        $path = 'invite-cards/'.$this->link->code.'-'.$format.'-'.$this->fingerprint($format).'.png';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->render($format);

        foreach ($disk->files('invite-cards') as $old) {
            if (str_starts_with(basename($old), $this->link->code.'-'.$format.'-')) {
                $disk->delete($old);
            }
        }

        $disk->put($path, $png);

        return $png;
    }

    /**
     * Everything the card shows; a change of any of it is a new file.
     */
    private function fingerprint(string $format): string
    {
        $inviter = $this->link->inviter;

        return substr(hash('sha256', json_encode([
            'v' => 1,
            $format,
            app()->getLocale(),
            $this->link->type->value,
            $this->link->options,
            $inviter->displayName(),
            $inviter->pubkey,
            $inviter->avatar_path,
            $this->link->clan?->only(['name', 'clantag']),
            (new InviteCopy($this->link))->clanName(),
            config('app.url'),
        ]) ?: ''), 0, 16);
    }

    public function render(string $format): string
    {
        [$width, $height] = self::FORMATS[$format];
        $big = imagecreatetruecolor(max(1, $width * self::SCALE), max(1, $height * self::SCALE));

        if ($big === false) {
            throw new RuntimeException('GD could not create the invite card.');
        }

        $this->image = $big;
        imagealphablending($this->image, true);
        imagefill($this->image, 0, 0, $this->color(self::GROUND));

        $format === 'wide' ? $this->drawWide() : $this->drawSquare();

        $out = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagecopyresampled($out, $this->image, 0, 0, 0, 0, $width, $height, $width * self::SCALE, $height * self::SCALE);

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    /* ---------- Layouts (coordinates at 1x) ----------------------------------------------------------------------- */

    private function drawWide(): void
    {
        $copy = new InviteCopy($this->link);
        $inviter = $this->link->inviter;

        $this->avatar($inviter, 64, 72, 104);
        $this->text($this->fit($inviter->displayName(), 'display', 46, 470), 'display', 46, 192, 116, self::INK);
        $this->rank(194, 140, 30);

        [$lines, $size] = $this->wrapShrinking($copy->cardQuestion(), 'display', [84, 72, 62], 610, 2);
        $y = 280;

        foreach ($lines as $line) {
            $this->text($line, 'display', $size, 62, $y, self::INK);
            $y += (int) round($size * 1.08);
        }

        foreach (array_slice($this->wrap($copy->cardSubline(), 'mono', 28, 620), 0, 2) as $index => $line) {
            $this->text($line, 'mono', 28, 64, $y - 12 + 40 * $index, self::INK_2);
        }

        $this->footer(64, 530, 56, 1136, 570, 26, 560);
        $this->motif(716, 104, 392);
    }

    private function drawSquare(): void
    {
        $copy = new InviteCopy($this->link);
        $inviter = $this->link->inviter;

        $this->avatar($inviter, 76, 76, 150);
        $this->text($this->fit($inviter->displayName(), 'display', 52, 440), 'display', 52, 76, 310, self::INK);
        $this->rank(78, 338, 34);

        [$lines, $size] = $this->wrapShrinking($copy->cardQuestion(), 'display', [104, 88, 74], 928, 2);
        $y = 640;

        foreach ($lines as $line) {
            $this->text($line, 'display', $size, 72, $y, self::INK);
            $y += (int) round($size * 1.08);
        }

        foreach (array_slice($this->wrap($copy->cardSubline(), 'mono', 34, 928), 0, 2) as $index => $line) {
            $this->text($line, 'mono', 34, 76, $y - 10 + 48 * $index, self::INK_2);
        }

        $this->footer(76, 964, 60, 1004, 1006, 28, 520);
        $this->motif(556, 76, 448);
    }

    /**
     * Logo tile, wordmark, and the link's address on the right.
     */
    private function footer(int $x, int $y, int $tile, int $right, int $baseline, int $urlSize, int $urlMax): void
    {
        $this->picture(public_path('icon-512.png'), $x, $y, $tile, (int) round($tile * 0.18));
        $wordX = $x + $tile + 18;
        $this->text('TWENTY ONE', 'display', (int) round($tile * 0.55), $wordX, $baseline, self::INK);
        $wordWidth = $this->width('TWENTY ONE', 'display', (int) round($tile * 0.55));
        $this->text('esports', 'mono', (int) round($tile * 0.36), $wordX + $wordWidth + 12, $baseline, self::INK_2);

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $address = $host.'/i/'.substr($this->link->code, 0, 6).'…';
        $size = $urlSize;

        while ($size > 16 && $this->width($address, 'mono', $size) > $urlMax) {
            $size -= 2;
        }

        $this->text($address, 'mono', $size, $right - $this->width($address, 'mono', $size), $baseline, self::INK_2);
    }

    /**
     * Chess: the board in its cube frame. Rocket League and clans: the
     * TWENTY ONE mark and the clan tile, never game art.
     */
    private function motif(int $x, int $y, int $size): void
    {
        if ($this->link->type->isChess()) {
            $this->board($x, $y + 12, $size - 16);

            return;
        }

        $copy = new InviteCopy($this->link);
        $clan = $this->link->type === InviteLinkType::Clan ? $this->link->clan : $copy->lineup()?->clan;
        $mark = (int) round($size * 0.52);
        $this->picture(public_path('icon-512.png'), $x + ($size - $mark) / 2, $y + 8, $mark, (int) round($mark * 0.18));

        $tile = (int) round($size * 0.26);
        $rowY = $y + $mark + 56;
        $this->clanTile($clan, $x, $rowY, $tile);
        $nameX = $x + $tile + 22;
        $nameSize = (int) round($size * 0.1);
        $nameMax = $size - $tile - 22;

        while ($nameSize > (int) round($size * 0.065) && $this->width($clan->name ?? '', 'display', $nameSize) > $nameMax) {
            $nameSize -= 2;
        }

        $this->text($this->fit($clan->name ?? '', 'display', $nameSize, $nameMax), 'display', $nameSize, $nameX, $rowY + (int) round($tile * 0.45), self::INK);
        $detail = $this->link->type === InviteLinkType::Series
            ? __(':mode, best of :bo', ['mode' => (string) $this->link->option('mode'), 'bo' => (int) $this->link->option('best_of')])
            : trans_choice(':count player|:count players', $clan?->members()->count() ?? 0);
        $this->text($detail, 'mono', (int) round($size * 0.07), $nameX, $rowY + (int) round($tile * 0.85), self::INK_2);
    }

    /* ---------- Pieces ------------------------------------------------------------------------------------------ */

    private function avatar(User $user, int $x, int $y, int $size): void
    {
        $radius = (int) round($size * 0.1);

        if ($user->avatar_path !== null && Storage::disk('public')->exists($user->avatar_path)) {
            $this->picture(Storage::disk('public')->path($user->avatar_path), $x, $y, $size, $radius);

            return;
        }

        $scale = $size * $this->s / 96;
        $tile = imagecreatetruecolor($this->pixels($size), $this->pixels($size));

        foreach (Blockpile::polygons($user->pubkey) as $shape) {
            $points = array_map(fn (int $value): int => (int) round($value * $scale), $shape['points']);
            imagefilledpolygon($tile, $points, $this->colorOn($tile, $shape['fill']));
        }

        $this->paste($tile, $x, $y, $size, $radius);
    }

    private function clanTile(?Clan $clan, int $x, int $y, int $size): void
    {
        $radius = (int) round($size * 0.14);
        $px = $this->pixels($size);
        $tile = imagecreatetruecolor($px, $px);

        // The app's tag tile: #F9B25F → #F7931A → #B9640A, top left to bottom right.
        for ($i = 0; $i < 2 * $px; $i++) {
            $t = $i / (2 * $px);
            $color = $t < 0.55 ? $this->mix('#F9B25F', '#F7931A', $t / 0.55) : $this->mix('#F7931A', '#B9640A', ($t - 0.55) / 0.45);
            imageline($tile, 0, $i, $i, 0, $this->colorOn($tile, $color));
        }

        $tag = (string) ($clan->clantag ?? '');
        $font = $this->font('display');
        $pt = $this->pt((int) round($size * 0.28));
        $box = imagettfbbox($pt, 0, $font, $tag) ?: [0, 0, 0, 0, 0, 0, 0, 0];
        imagettftext($tile, $pt, 0, (int) (($px - ($box[2] - $box[0])) / 2), (int) (($px + abs($box[7] - $box[1])) / 2), $this->colorOn($tile, '#17120A'), $font, $tag);

        $this->paste($tile, $x, $y, $size, $radius);
    }

    /**
     * The start position in the board kit's colours ("house" theme), in the
     * cube frame of the landing: top face lighter, right face darker.
     */
    private function board(int $x, int $y, int $size): void
    {
        $s = $this->s;
        $depth = (int) round($size * 0.07);
        $frame = '#3A2C14';
        [$fx, $fy, $fs] = [$x * $s, $y * $s, $size * $s];
        $d = $depth * $s;

        imagefilledpolygon($this->image, $this->ints([$fx, $fy, $fx + $d, $fy - $d, $fx + $fs + $d, $fy - $d, $fx + $fs, $fy]), $this->color('#4B391A'));
        imagefilledpolygon($this->image, $this->ints([$fx + $fs, $fy, $fx + $fs + $d, $fy - $d, $fx + $fs + $d, $fy + $fs - $d, $fx + $fs, $fy + $fs]), $this->color('#241B0C'));
        imagefilledrectangle($this->image, (int) $fx, (int) $fy, (int) ($fx + $fs), (int) ($fy + $fs), $this->color($frame));

        $border = (int) round($size * 0.03 * $s);
        $cell = ($fs - 2 * $border) / 8;
        $start = ['rnbqkbnr', 'pppppppp', '', '', '', '', 'PPPPPPPP', 'RNBQKBNR'];
        $font = resource_path('fonts/og/DejaVuSans-Chess.ttf');
        $pt = $cell * 0.92 * 0.75;

        for ($r = 0; $r < 8; $r++) {
            for ($f = 0; $f < 8; $f++) {
                $cx = $fx + $border + $f * $cell;
                $cy = $fy + $border + $r * $cell;
                imagefilledrectangle($this->image, (int) round($cx), (int) round($cy), (int) round($cx + $cell) - 1, (int) round($cy + $cell) - 1, $this->color(($r + $f) % 2 === 0 ? '#CFCFD4' : '#62626C'));

                $piece = $start[$r][$f] ?? '';

                if ($piece === '') {
                    continue;
                }

                $index = strpos('kqrbnp', strtolower($piece));
                $white = $piece !== strtolower($piece);
                $solid = mb_chr(0x265A + (int) $index);
                $box = imagettfbbox($pt, 0, $font, $solid) ?: [0, 0, 0, 0, 0, 0, 0, 0];
                $gx = (int) round($cx + ($cell - ($box[2] - $box[0])) / 2 - $box[0]);
                $gy = (int) round($cy + $cell * 0.86);
                imagettftext($this->image, $pt, 0, $gx, $gy, $this->color($white ? '#FFFFFF' : '#0A0A0B'), $font, $solid);

                if ($white) {
                    imagettftext($this->image, $pt, 0, $gx, $gy, $this->color('#0A0A0B'), $font, mb_chr(0x2654 + (int) $index));
                }
            }
        }
    }

    /**
     * The rank line. Ratings arrive with P7; until a player has one the card
     * says "Provisional", with the dashed cube of the rank badge.
     */
    private function rank(int $x, int $y, int $size): void
    {
        $s = $this->s;
        $cube = $size * 0.9 * $s;
        [$cx, $cy] = [$x * $s + $cube / 2, $y * $s + $cube / 2];
        $hex = fn (float $r): array => $this->ints([$cx, $cy - $r, $cx + $r * 0.9, $cy - $r / 2, $cx + $r * 0.9, $cy + $r / 2, $cx, $cy + $r, $cx - $r * 0.9, $cy + $r / 2, $cx - $r * 0.9, $cy - $r / 2]);
        imagesetthickness($this->image, (int) max(2, round($s * 2)));
        imagepolygon($this->image, $hex($cube / 2), $this->color(self::INK_2));
        imagesetthickness($this->image, 1);
        imagefilledpolygon($this->image, $this->ints([$cx, $cy - $cube / 2, $cx + $cube * 0.45, $cy - $cube / 4, $cx, $cy, $cx - $cube * 0.45, $cy - $cube / 4]), $this->color('#5C5C60'));

        $this->text(__('Provisional'), 'mono-bold', $size, $x + (int) round($size * 1.25), $y + (int) round($size * 0.82), self::INK_2);
    }

    /* ---------- Text ---------------------------------------------------------------------------------------------- */

    private function text(string $text, string $face, int $px, int|float $x, int|float $baseline, string $color): void
    {
        imagettftext($this->image, $this->pt($px) * $this->s, 0, (int) round($x * $this->s), (int) round($baseline * $this->s), $this->color($color), $this->font($face), $this->printable($text));
    }

    private function width(string $text, string $face, int $px): int
    {
        $box = imagettfbbox($this->pt($px), 0, $this->font($face), $this->printable($text)) ?: [0, 0, 0, 0, 0, 0, 0, 0];

        return (int) ceil($box[2] - $box[0]);
    }

    /**
     * Cut with an ellipsis to fit.
     */
    private function fit(string $text, string $face, int $px, int $max): string
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
     * @return list<string>
     */
    private function wrap(string $text, string $face, int $px, int $max): array
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
     * The largest size at which the text fits in `maxLines`; the last size
     * cuts what is left.
     *
     * @param  list<int>  $sizes
     * @return array{0: list<string>, 1: int}
     */
    private function wrapShrinking(string $text, string $face, array $sizes, int $max, int $maxLines): array
    {
        foreach ($sizes as $size) {
            $lines = $this->wrap($text, $face, $size, $max);

            if (count($lines) <= $maxLines) {
                return [$lines, $size];
            }
        }

        $size = end($sizes) ?: 60;
        $lines = $this->wrap($text, $face, $size, $max);
        $kept = array_slice($lines, 0, $maxLines);
        $kept[$maxLines - 1] = $this->fit($kept[$maxLines - 1].' '.implode(' ', array_slice($lines, $maxLines)), $face, $size, $max);

        return [array_values($kept), $size];
    }

    /**
     * Only characters the bundled font subsets draw (Latin, Latin-1, Latin
     * Extended A/B, punctuation): anything else, emoji included, is dropped
     * rather than drawn as empty boxes.
     */
    private function printable(string $text): string
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

    /**
     * A tile's side in pixels of the drawing (twice the card's size).
     *
     * @return int<1, max>
     */
    private function pixels(int $size): int
    {
        return max(1, (int) round($size * $this->s));
    }

    /** GD sizes text in points at 96 dpi. */
    private function pt(int|float $px): float
    {
        return $px * 0.75;
    }

    /* ---------- Raster helpers ------------------------------------------------------------------------------------ */

    private function picture(string $path, int|float $x, int|float $y, int $size, int $radius): void
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
     * Copy a tile onto the card with rounded corners (corners filled with the
     * ground colour).
     */
    private function paste(GdImage $tile, int|float $x, int|float $y, int $size, int $radius): void
    {
        $px = imagesx($tile);
        $r = (int) round($radius * $this->s);
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

        imagecopy($this->image, $tile, (int) round($x * $this->s), (int) round($y * $this->s), 0, 0, $px, $px);
    }

    private function color(string $hex): int
    {
        return $this->colorOn($this->image, $hex);
    }

    private function colorOn(GdImage $image, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [0, 0, 0];
        $channel = fn (mixed $value): int => max(0, min(255, (int) $value));

        return (int) imagecolorallocate($image, $channel($r), $channel($g), $channel($b));
    }

    private function mix(string $from, string $to, float $t): string
    {
        $a = sscanf($from, '#%02x%02x%02x') ?: [0, 0, 0];
        $b = sscanf($to, '#%02x%02x%02x') ?: [0, 0, 0];

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $i): int => (int) round($a[$i] + ($b[$i] - $a[$i]) * max(0, min(1, $t))), [0, 1, 2]));
    }

    /**
     * @param  list<int|float>  $points
     * @return list<int>
     */
    private function ints(array $points): array
    {
        return array_map(fn (int|float $value): int => (int) round($value), $points);
    }
}
