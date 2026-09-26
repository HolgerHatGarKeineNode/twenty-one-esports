<?php

namespace App\Support\Seo;

use Illuminate\Http\Request;

/**
 * Whether search engines may index this installation at all (P14): only the
 * production environment on the host of APP_URL. Every other host (the
 * `*.on-forge.com` interim domain, a staging copy, a local server) answers
 * robots.txt with `Disallow: /` and marks every page `noindex`, so a copy of
 * the site never competes with the real one. Anything unclear (no APP_URL
 * host) counts as "not here".
 */
final class SearchIndexing
{
    public static function allowed(?Request $request = null): bool
    {
        $request ??= request();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return app()->environment('production')
            && is_string($host)
            && strcasecmp($request->getHost(), $host) === 0;
    }
}
