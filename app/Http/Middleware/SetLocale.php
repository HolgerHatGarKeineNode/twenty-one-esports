<?php

namespace App\Http\Middleware;

use App\Support\Seo\LocalizedUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the visitor's locale: the session choice wins over the user's
     * stored preference, and that over the browser's language; anything
     * unsupported keeps the application locale.
     *
     * `?lang=<locale>` (P14) addresses one language version of a page, the
     * hreflang alternates of App\Support\Seo\LocalizedUrls. It is stored like
     * the language switch, so the next page stays in that language.
     *
     * The browser's language (Accept-Language) counts only for a visitor who
     * chose none: a friend on a German phone who opens an invite or the
     * practice page reads German, not English. It is never stored, so the
     * switch and `?lang=` still decide for the session; a new account keeps
     * the language its owner saw (NostrLoginController stores the locale).
     * Googlebot sends no Accept-Language and still reads the application
     * locale, the hreflang versions stay as they are.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query(LocalizedUrls::QUERY);

        if (is_string($requested) && in_array($requested, config('app.supported_locales'), true)) {
            $request->session()->put('locale', $requested);
        }

        $locale = $request->session()->get('locale')
            ?? $request->user()?->getAttribute('locale')
            ?? $this->browserLocale($request);

        if (is_string($locale) && in_array($locale, config('app.supported_locales'), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * The first supported language the browser asks for, best quality first
     * (`fr-FR,fr;q=0.9,de;q=0.8` is German), or null when it asks for none.
     */
    private function browserLocale(Request $request): ?string
    {
        $supported = config('app.supported_locales');

        foreach (AcceptHeader::fromString($request->headers->get('Accept-Language'))->all() as $item) {
            // `de-DE` and `de` are German; `q=0` means "not this one".
            $language = strtolower(explode('-', str_replace('_', '-', $item->getValue()))[0]);

            if ($item->getQuality() > 0 && in_array($language, $supported, true)) {
                return $language;
            }
        }

        return null;
    }
}
