<?php

namespace App\Support\Prizes;

use App\Support\Clans\ClanLogos;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Sponsor logos (P9). Validated like clan logos (PNG, JPEG or WebP by the
 * bytes, never SVG; {@see ClanLogos::rules()}) and redrawn with GD, so what
 * lands on the public disk is a plain PNG the league made itself. Unlike a
 * clan logo it keeps its aspect ratio (sponsor logos are wide): it is
 * scaled to fit MAX_WIDTH × MAX_HEIGHT. The file name is the SHA-256 of the
 * PNG. Logos stay in the league's storage; nothing of them goes to Nostr.
 */
final class SponsorLogos
{
    public const DIRECTORY = 'sponsor-logos';

    public const MAX_WIDTH = 480;

    public const MAX_HEIGHT = 240;

    /**
     * @throws TournamentRuleViolation when GD cannot read the image
     */
    public function store(UploadedFile $file): string
    {
        $bytes = @file_get_contents($file->getRealPath() ?: '');
        $source = $bytes === false || $bytes === '' ? false : @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new TournamentRuleViolation('logo', __('Use a PNG, JPG or WebP image. SVG is not supported.'));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_WIDTH / max(1, $width), self::MAX_HEIGHT / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();

        $path = self::DIRECTORY.'/'.hash('sha256', $png).'.png';
        Storage::disk('public')->put($path, $png);

        return $path;
    }
}
