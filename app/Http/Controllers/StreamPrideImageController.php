<?php

namespace App\Http\Controllers;

use App\Support\StreamBot\PrideNotes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A slide a pride note carries (PrideNotes), by its content hash: the same
 * URL is always the same picture, so clients may keep it for good.
 */
class StreamPrideImageController extends Controller
{
    public function __invoke(string $hash): BinaryFileResponse
    {
        $path = PrideNotes::imagePath($hash);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
