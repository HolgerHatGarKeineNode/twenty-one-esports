<?php

namespace App\Support\TwentyOne\Stream;

use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\User;
use GdImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes the files StreamImages reads (`twentyone:stream:images`, every
 * 10 minutes):
 *
 * - avatars: for the `max_users` most recently updated players, their
 *   picture (our upload, else the kind-0 picture via ImageFetcher) redrawn
 *   by GD as a 128x128 JPEG (q80, centre square) under
 *   `avatars/<sha1(user_id|source)>.jpg`. A file is kept for
 *   `refresh_seconds`, then fetched again. A failure keeps the old file,
 *   writes a `.failed` marker that holds the next try back for
 *   `retry_seconds`, logs one line and moves on to the next player. Files
 *   of pictures no player uses any more are removed.
 * - clan logos: every clan's own redrawn logo (ClanLogos, `clan-logos/<sha256>.png`
 *   on the public disk; a foreign `picture` never) as a 128x128 PNG with its
 *   alpha under `logos/`, rebuilt when the original's mtime changes; files
 *   of logos no clan uses any more are removed.
 * - backdrops: every game cover (GameRegistry::coverPath) and the brand
 *   cover, blurred, darkened and written as a 640x360 JPEG (q60) under
 *   `backdrops/<slug>.jpg`, again only when the source's mtime changed
 *   (the backdrop carries its source's mtime). Every game cover also gets its
 *   tile: the centre 16:9, sharp, at the size d2 draws it (288x162, q90) under
 *   `tiles/<slug>.jpg`, so rsvg-convert no longer decodes and scales a 1280 px
 *   cover per tile on every render (four of them were half of d2's render time).
 *
 * The picture is redrawn, never copied: what reaches the scene is a JPEG
 * GD wrote. Dimensions are read from the header first (getimagesizefromstring),
 * so a decompression bomb is refused before it is decoded.
 */
class StreamImageBuilder
{
    public const AVATAR_SIDE = 128;

    public const LOGO_SIDE = 128;

    /** A logo copy above this is written again with a 255-colour palette (alpha kept). */
    public const LOGO_MAX_BYTES = 30 * 1024;

    public const BACKDROP_WIDTH = 640;

    public const BACKDROP_HEIGHT = 360;

    /** A cover tile's size: the cover box of d2 (resources/views/stream/rotation/d2-cups.blade.php), drawn 1:1. */
    public const COVER_TILE_WIDTH = 288;

    public const COVER_TILE_HEIGHT = 162;

    /** GD alpha of the black veil over a backdrop: 57/127 lets ~45 % of the light through (darkened by ~55 %). */
    private const BACKDROP_VEIL_ALPHA = 57;

    public function __construct(private ImageFetcher $fetcher, private GameRegistry $games) {}

    /**
     * Refresh every avatar that is due; the counts per outcome.
     *
     * @return array{fetched: int, fresh: int, waiting: int, failed: int, none: int, removed: int}
     */
    public function refreshAvatars(): array
    {
        $counts = ['fetched' => 0, 'fresh' => 0, 'waiting' => 0, 'failed' => 0, 'none' => 0, 'removed' => 0];
        $keep = [];
        $users = User::query()->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(max(1, (int) config('twentyone.stream.images.max_users', 500)))
            ->get(['id', 'pubkey', 'avatar_path', 'picture', 'updated_at']);

        File::ensureDirectoryExists(StreamImages::dir().'/avatars');

        foreach ($users as $user) {
            $source = StreamImages::source($user);

            if ($source !== null) {
                $keep[basename(StreamImages::avatarFile($user->id, $source), '.jpg')] = true;
            }

            $counts[$this->refreshAvatar($user)]++;
        }

        foreach (File::files(StreamImages::dir().'/avatars') as $file) {
            if (! isset($keep[strtok($file->getFilename(), '.')])) {
                File::delete($file->getPathname());
                $counts['removed']++;
            }
        }

        return $counts;
    }

    /**
     * The avatars that failed last time, per host: how many players and the
     * latest reason. Read from the failure markers, so a player stays listed
     * until a fetch works again; prod logs errors only, this is what shows it.
     *
     * @return array<string, array{players: int, reason: string}>
     */
    public function avatarFailures(): array
    {
        $failures = [];

        foreach (glob(StreamImages::dir().'/avatars/*.failed') ?: [] as $marker) {
            [$from, $reason] = array_pad(explode("\n", (string) @file_get_contents($marker), 2), 2, '');
            $host = parse_url($from, PHP_URL_HOST) ?: ($from !== '' ? $from : 'unknown');
            $failures[$host]['players'] = ($failures[$host]['players'] ?? 0) + 1;
            $failures[$host]['reason'] = $reason !== '' ? $reason : 'no reason kept';
        }

        ksort($failures);

        return $failures;
    }

    /**
     * One player's avatar: 'none' without a picture (the scene draws the
     * Blockpile), 'fresh' when the file is recent, 'waiting' after a recent
     * failure, else 'fetched' or 'failed'. Never throws.
     *
     * @return 'fetched'|'fresh'|'waiting'|'failed'|'none'
     */
    public function refreshAvatar(User $user): string
    {
        $source = StreamImages::source($user);

        if ($source === null) {
            return 'none';
        }

        $path = StreamImages::avatarFile($user->id, $source);
        $marker = substr($path, 0, -4).'.failed';
        $now = now()->getTimestamp();

        if (is_file($path) && $now - (int) filemtime($path) < (int) config('twentyone.stream.images.refresh_seconds', 86400)) {
            return 'fresh';
        }

        if (is_file($marker) && $now - (int) filemtime($marker) < (int) config('twentyone.stream.images.retry_seconds', 3600)) {
            return 'waiting';
        }

        try {
            $jpeg = $this->avatarJpeg($this->sourceBytes($source));
            File::ensureDirectoryExists(dirname($path));
            PlaylistWriter::writeAtomically($path, $jpeg);
            touch($path, $now);
            File::delete($marker);

            return 'fetched';
        } catch (Throwable $e) {
            // One line per failure; the URL without its query (it may carry a token).
            $from = str_starts_with($source, 'public:') ? $source : ImageFetcher::loggable($source);
            Log::warning('stream avatar of user '.$user->id.' not refreshed from '.$from.': '.$e->getMessage());

            try {
                // The marker keeps where and why, for avatarFailures().
                File::ensureDirectoryExists(dirname($marker));
                File::put($marker, $from."\n".$e->getMessage());
                touch($marker, $now);
            } catch (Throwable) {
                // Without the marker the next run simply tries again.
            }

            return 'failed';
        }
    }

    /**
     * Copy every clan's redrawn logo small; the counts per outcome.
     *
     * @return array{built: int, fresh: int, failed: int, removed: int}
     */
    public function refreshLogos(): array
    {
        $counts = ['built' => 0, 'fresh' => 0, 'failed' => 0, 'removed' => 0];
        $keep = [];
        $disk = Storage::disk('public');
        File::ensureDirectoryExists(StreamImages::dir().'/logos');

        foreach (Clan::query()->whereNotNull('picture')->orderBy('id')->get(['id', 'picture']) as $clan) {
            $ref = StreamImages::logoRef($clan);

            if ($ref === null) {
                continue;
            }

            $target = StreamImages::logoFile($ref);
            $keep[basename($target)] = true;

            try {
                $source = $disk->path($ref);
                $mtime = (int) filemtime($source);

                if (is_file($target) && (int) filemtime($target) === $mtime) {
                    $counts['fresh']++;

                    continue;
                }

                PlaylistWriter::writeAtomically($target, $this->logoPng((string) file_get_contents($source)));
                touch($target, $mtime);
                $counts['built']++;
            } catch (Throwable $e) {
                Log::warning('stream logo of clan '.$clan->id.' not built from '.$ref.': '.$e->getMessage());
                $counts['failed']++;
            }
        }

        foreach (File::files(StreamImages::dir().'/logos') as $file) {
            if (! isset($keep[$file->getFilename()]) && ! str_ends_with($file->getFilename(), '.tmp')) {
                File::delete($file->getPathname());
                $counts['removed']++;
            }
        }

        return $counts;
    }

    /**
     * A logo redrawn as a 128x128 PNG with its alpha (centre square); past
     * LOGO_MAX_BYTES again with a 255-colour palette and one transparent
     * index (alpha then only on or off: at least half transparent is clear).
     *
     * @throws StreamImageFailed
     */
    public function logoPng(string $bytes): string
    {
        $image = $this->decode($bytes);
        $side = min(imagesx($image), imagesy($image));
        $logo = imagecreatetruecolor(self::LOGO_SIDE, self::LOGO_SIDE);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, (int) imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagecopyresampled($logo, $image, 0, 0, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), self::LOGO_SIDE, self::LOGO_SIDE, $side, $side);
        $png = $this->png($logo);

        if (strlen($png) > self::LOGO_MAX_BYTES) {
            // GD's palette conversion drops the alpha (measured 2026-09-27): keep a
            // mask of the (mostly) transparent pixels and give them one clear index.
            $clearPixels = [];

            for ($y = 0; $y < self::LOGO_SIDE; $y++) {
                for ($x = 0; $x < self::LOGO_SIDE; $x++) {
                    if (((imagecolorat($logo, $x, $y) >> 24) & 0x7F) >= 64) {
                        $clearPixels[] = [$x, $y];
                    }
                }
            }

            imagetruecolortopalette($logo, false, 255);
            $clear = (int) imagecolorallocatealpha($logo, 0, 0, 0, 127);
            imagecolortransparent($logo, $clear);

            foreach ($clearPixels as [$x, $y]) {
                imagesetpixel($logo, $x, $y, $clear);
            }

            $png = $this->png($logo);
        }

        return $png;
    }

    /**
     * Build every backdrop whose source changed; the counts per outcome.
     *
     * @return array{built: int, fresh: int, failed: int}
     */
    public function buildBackdrops(): array
    {
        $counts = ['built' => 0, 'fresh' => 0, 'failed' => 0];
        $sources = [StreamImages::BRAND => public_path('images/twentyone/cover.png')];

        foreach (array_keys($this->games->all()) as $slug) {
            $cover = $this->games->coverPath($slug);

            if ($cover !== null) {
                $sources[$slug] = $cover;
            }
        }

        File::ensureDirectoryExists(StreamImages::dir().'/backdrops');

        foreach ($sources as $slug => $source) {
            $target = StreamImages::backdropFile($slug);
            // The brand cover is only ever a backdrop; a game cover is a d2 tile too.
            $tile = $slug === StreamImages::BRAND ? null : StreamImages::coverTileFile($slug);

            try {
                $mtime = (int) filemtime($source);

                if (is_file($target) && (int) filemtime($target) === $mtime && ($tile === null || (is_file($tile) && (int) filemtime($tile) === $mtime))) {
                    $counts['fresh']++;

                    continue;
                }

                $bytes = (string) file_get_contents($source);
                PlaylistWriter::writeAtomically($target, $this->backdropJpeg($bytes));
                touch($target, $mtime);

                if ($tile !== null) {
                    File::ensureDirectoryExists(dirname($tile));
                    PlaylistWriter::writeAtomically($tile, $this->coverTileJpeg($bytes));
                    touch($tile, $mtime);
                }

                $counts['built']++;
            } catch (Throwable $e) {
                Log::warning('stream backdrop '.$slug.' not built from '.basename($source).': '.$e->getMessage());
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * A picture redrawn as the avatar JPEG: centre square, 128x128, q80;
     * transparency lands on the scenes' dark ground.
     *
     * @throws StreamImageFailed
     */
    public function avatarJpeg(string $bytes): string
    {
        $image = $this->decode($bytes);
        $side = min(imagesx($image), imagesy($image));
        $avatar = imagecreatetruecolor(self::AVATAR_SIDE, self::AVATAR_SIDE);
        imagefill($avatar, 0, 0, (int) imagecolorallocate($avatar, 0x14, 0x14, 0x14));
        imagealphablending($avatar, true);
        imagecopyresampled($avatar, $image, 0, 0, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), self::AVATAR_SIDE, self::AVATAR_SIDE, $side, $side);

        return $this->jpeg($avatar, 80);
    }

    /**
     * A cover redrawn as a backdrop: the centre 16:9, blurred (downscale to
     * 128x72, gaussian passes, smooth upscale, more passes), darkened,
     * 640x360, q60.
     *
     * @throws StreamImageFailed
     */
    public function backdropJpeg(string $bytes): string
    {
        $image = $this->decode($bytes);
        [$width, $height] = [imagesx($image), imagesy($image)];
        [$cropWidth, $cropHeight] = $width * 9 > $height * 16 ? [intdiv($height * 16, 9), $height] : [$width, intdiv($width * 9, 16)];

        // Small first (the blur is cheap there), a smooth filter up (no visible grid), then smooth again.
        // IMG_BICUBIC fails on upscaling with the bundled GD (measured 2026-09-27); Mitchell, else bilinear.
        $small = imagecreatetruecolor(128, 72);
        imagecopyresampled($small, $image, 0, 0, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2), 128, 72, $cropWidth, $cropHeight);

        for ($pass = 0; $pass < 6; $pass++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        $backdrop = imagescale($small, self::BACKDROP_WIDTH, self::BACKDROP_HEIGHT, IMG_MITCHELL)
            ?: imagescale($small, self::BACKDROP_WIDTH, self::BACKDROP_HEIGHT, IMG_BILINEAR_FIXED);

        if ($backdrop === false) {
            throw new StreamImageFailed('the cover could not be scaled');
        }

        for ($pass = 0; $pass < 6; $pass++) {
            imagefilter($backdrop, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagealphablending($backdrop, true);
        imagefilledrectangle($backdrop, 0, 0, self::BACKDROP_WIDTH - 1, self::BACKDROP_HEIGHT - 1, (int) imagecolorallocatealpha($backdrop, 0, 0, 0, self::BACKDROP_VEIL_ALPHA));

        return $this->jpeg($backdrop, 60);
    }

    /**
     * A cover redrawn as a d2 tile: the centre 16:9, sharp, 288x162, q90.
     *
     * @throws StreamImageFailed
     */
    public function coverTileJpeg(string $bytes): string
    {
        $image = $this->decode($bytes);
        [$width, $height] = [imagesx($image), imagesy($image)];
        [$cropWidth, $cropHeight] = $width * 9 > $height * 16 ? [intdiv($height * 16, 9), $height] : [$width, intdiv($width * 9, 16)];
        $tile = imagecreatetruecolor(self::COVER_TILE_WIDTH, self::COVER_TILE_HEIGHT);
        imagecopyresampled($tile, $image, 0, 0, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2), self::COVER_TILE_WIDTH, self::COVER_TILE_HEIGHT, $cropWidth, $cropHeight);

        return $this->jpeg($tile, 90);
    }

    /**
     * The bytes behind a source: our upload from the public disk, else the
     * remote picture through the guarded fetcher.
     *
     * @throws StreamImageFailed
     */
    private function sourceBytes(string $source): string
    {
        if (! str_starts_with($source, 'public:')) {
            return $this->fetcher->fetch($source);
        }

        $bytes = Storage::disk('public')->get(substr($source, 7));

        if ($bytes === null || $bytes === '') {
            throw new StreamImageFailed('the uploaded file is missing');
        }

        if (strlen($bytes) > (int) config('twentyone.stream.images.max_bytes', 8 * 1024 * 1024)) {
            throw new StreamImageFailed('the uploaded file is too large');
        }

        return $bytes;
    }

    /**
     * Decode after the header check: both sides at most `max_side`.
     *
     * @throws StreamImageFailed
     */
    private function decode(string $bytes): GdImage
    {
        $size = @getimagesizefromstring($bytes);
        $max = (int) config('twentyone.stream.images.max_side', 4096);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new StreamImageFailed('not a readable image');
        }

        if ($size[0] > $max || $size[1] > $max) {
            throw new StreamImageFailed('image is '.$size[0].'x'.$size[1].', more than '.$max.' px a side');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new StreamImageFailed('the image could not be decoded');
        }

        return $image;
    }

    private function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    private function jpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
