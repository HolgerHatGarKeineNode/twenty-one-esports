<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The stream's 30311 picture (StreamCover): the one file the stream daemon
 * overwrites with the next slide. Served here, not under /live/, whose nginx
 * block knows only the HLS types. The URL carries the picture's hash, so a
 * client may keep it for a day; a new picture comes with a new URL.
 */
class StreamCoverController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $path = (string) config('twentyone.stream.cover.path');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
