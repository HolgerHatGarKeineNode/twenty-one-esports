<?php

namespace App\Http\Controllers;

use App\Support\Nostr\Blockpile;
use Illuminate\Http\Response;

/**
 * The Blockpile avatar of a public key as an SVG file (GeneratedAvatar.dc.html,
 * step 8: "render once per npub as SVG and cache it"). A pure function of the
 * key, so browsers may keep it for a year; `?v=` in the URL changes when the
 * drawing ever does. Same origin: no visitor IP reaches a third party.
 */
class GeneratedAvatarController extends Controller
{
    public function __invoke(string $pubkey): Response
    {
        return response(Blockpile::svg($pubkey), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
