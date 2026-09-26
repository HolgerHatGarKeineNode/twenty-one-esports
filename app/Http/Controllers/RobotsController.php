<?php

namespace App\Http\Controllers;

use App\Support\Seo\SearchIndexing;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /robots.txt (P14). A route and not a file in public/, because the sitemap
 * line needs the absolute URL of this installation.
 *
 * Kept out: the admin area, settings, the test fixtures, private clan invites
 * and the other pages only a logged-in player reaches (a crawler only ever
 * sees their redirect to /login). The invite deep links `/i/{code}` stay
 * reachable on purpose: link previews (X, Telegram) fetch them and their
 * card images, and the landing itself says `noindex`.
 *
 * Outside production, or on any host but the one of APP_URL (the
 * `*.on-forge.com` interim domain), everything is disallowed.
 */
class RobotsController extends Controller
{
    /** Paths no crawler needs. `$` ends a pattern (RFC 9309). */
    public const DISALLOW = [
        '/admin',
        '/settings',
        '/__test',
        '/invites/',
        '/me$',
        '/me/',
        '/clans/create',
        '/clans/*/manage',
        '/challenges/',
        '/chess/challenge',
        '/matches/*/room',
        '/players/*/card',
        '/locale/',
        '/styleguide',
    ];

    public function __invoke(Request $request): Response
    {
        $lines = ['User-agent: *'];

        // Any host but the production one (an interim domain, staging) is closed to crawlers.
        if (! SearchIndexing::allowed($request)) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (self::DISALLOW as $path) {
                $lines[] = 'Disallow: '.$path;
            }

            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap');
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
