<?php

namespace App\Http\Controllers;

use App\Support\Badges\BadgeImage;
use Illuminate\Http\Response;

/**
 * The artwork of a rank badge, `image` (1024) and `thumb` (256) of the NIP-58
 * definition. Public and without a session: Nostr clients fetch it. The URL
 * names game, tier and artwork version only, so it never changes its
 * picture and may be cached for a year.
 */
class BadgeImageController extends Controller
{
    public function __invoke(BadgeImage $images, string $game, string $tier, int $artwork, int $size = 1024): Response
    {
        abort_unless($artwork === (int) config('esports.badges.artwork') && $images->exists($game, $tier) && in_array($size, BadgeImage::SIZES, true), 404);

        return response($images->png($game, $tier, $size), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
