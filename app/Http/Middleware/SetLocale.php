<?php

namespace App\Http\Middleware;

use App\Support\Seo\LocalizedUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the visitor's locale: the session choice wins over the user's
     * stored preference; anything unsupported keeps the application locale.
     *
     * `?lang=<locale>` (P14) addresses one language version of a page, the
     * hreflang alternates of App\Support\Seo\LocalizedUrls. It is stored like
     * the language switch, so the next page stays in that language.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query(LocalizedUrls::QUERY);

        if (is_string($requested) && in_array($requested, config('app.supported_locales'), true)) {
            $request->session()->put('locale', $requested);
        }

        $locale = $request->session()->get('locale') ?? $request->user()?->getAttribute('locale');

        if (is_string($locale) && in_array($locale, config('app.supported_locales'), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
