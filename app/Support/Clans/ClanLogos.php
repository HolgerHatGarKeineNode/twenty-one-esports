<?php

namespace App\Support\Clans;

use App\Models\Clan;
use Closure;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Clan logos uploaded on the manage page. An upload is redrawn with GD into a
 * square PNG (center crop, SIZE px), so what lands on the public disk is
 * always a plain raster the league made itself: no SVG, no metadata, no
 * foreign encoder quirks. The file name is the SHA-256 of that PNG, so the
 * same logo always has the same URL, and the URL in the signed clan event
 * (`picture`) can be computed before anything is written.
 *
 * Redrawing is deterministic (same upload, same bytes), which is what lets
 * the manage page compute the URL when it prepares the event and write the
 * file only after the signed event was accepted.
 */
final class ClanLogos
{
    public const DIRECTORY = 'clan-logos';

    public const SIZE = 512;

    /** Upper bound per side before decoding: keeps a small file from inflating into a huge bitmap. */
    public const MAX_SIDE = 3000;

    /**
     * Validation rules for the upload: at most 2 MB, PNG, JPEG or WebP by the
     * bytes themselves (getimagesize), never by name or declared type. SVG is
     * refused. `bail`: one clear message per file.
     *
     * @return list<string|Closure>
     */
    public static function rules(): array
    {
        return [
            'bail',
            'file',
            'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                $path = $value instanceof UploadedFile ? $value->getRealPath() : false;
                $size = is_string($path) && is_file($path) ? @getimagesize($path) : false;

                if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
                    $fail(__('Use a PNG, JPG or WebP image. SVG is not supported.'));
                }
            },
            'mimes:png,jpg,jpeg,webp',
            'dimensions:min_width=64,min_height=64,max_width='.self::MAX_SIDE.',max_height='.self::MAX_SIDE,
        ];
    }

    /**
     * The upload redrawn as a SIZE x SIZE PNG.
     *
     * @throws ClanRuleViolation when GD cannot read the image
     */
    public function render(UploadedFile $file): string
    {
        $bytes = @file_get_contents($file->getRealPath() ?: '');
        $source = $bytes === false || $bytes === '' ? false : @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new ClanRuleViolation(__('This file could not be read as an image.'));
        }

        $source = $this->upright($source, $bytes);
        $side = min(imagesx($source), imagesy($source));

        $logo = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, (int) imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagecopyresampled($logo, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagepng($logo, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Disk path of a rendered logo, `clan-logos/<sha256>.png`.
     */
    public function pathFor(string $png): string
    {
        return self::DIRECTORY.'/'.hash('sha256', $png).'.png';
    }

    /**
     * Absolute URL of a rendered logo, as it goes into the `picture` tag.
     */
    public function urlFor(string $png): string
    {
        return $this->absoluteUrl($this->pathFor($png));
    }

    /**
     * Write a rendered logo; false (and reported) when the disk refuses.
     */
    public function store(string $png): bool
    {
        $disk = Storage::disk('public');
        $path = $this->pathFor($png);

        try {
            if ($disk->exists($path) || $disk->put($path, $png)) {
                return true;
            }

            report(new RuntimeException("Clan logo could not be written to {$path}."));
        } catch (Throwable $failed) {
            report(new RuntimeException("Clan logo could not be written to {$path}.", previous: $failed));
        }

        return false;
    }

    /**
     * Delete a logo file when it is one of ours and no clan points at it any
     * more. Anything else (a portal logo, a URL from an older APP_URL) is left
     * alone: when in doubt, the file stays.
     */
    public function deleteIfUnused(?string $url): void
    {
        $path = $this->pathOf($url);

        if ($path === null || Clan::query()->where('picture', $url)->exists()) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * The disk path behind one of our logo URLs, or null (a portal logo, an
     * older APP_URL, anything that is not `clan-logos/<sha256>.png`).
     */
    public function pathOf(?string $url): ?string
    {
        $prefix = $this->absoluteUrl(self::DIRECTORY).'/';

        if ($url === null || ! str_starts_with($url, $prefix)) {
            return null;
        }

        $name = substr($url, strlen($prefix));

        return preg_match('/^[0-9a-f]{64}\.png$/', $name) === 1 ? self::DIRECTORY.'/'.$name : null;
    }

    /**
     * A Nostr `picture` must be absolute; a disk configured with a relative
     * URL (or none) gets the app's own origin.
     */
    private function absoluteUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
    }

    /**
     * Phone photos store their rotation in EXIF instead of the pixels; GD
     * ignores it. Apply it when the exif extension is there.
     */
    private function upright(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
