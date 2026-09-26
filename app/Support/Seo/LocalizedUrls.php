<?php

namespace App\Support\Seo;

/**
 * The language versions of one page (P14). The interface language is a
 * session choice, so every page has one URL; `?lang=<locale>` picks a
 * language for that request (App\Http\Middleware\SetLocale) and makes each
 * version addressable for search engines:
 *
 *   /clans             English, and the x-default a visitor without a choice gets
 *   /clans?lang=de     German
 *
 * The canonical URL of a page is the version of the language it was rendered
 * in. Every other query parameter (filters, tabs, search) is dropped from it,
 * except the ones in KEPT_QUERY, which name a different page of a list.
 */
final class LocalizedUrls
{
    /** The query parameter that picks the language of one request. */
    public const QUERY = 'lang';

    /** Query parameters that address a different page, not a view of the same one. */
    private const KEPT_QUERY = ['page'];

    /**
     * The locale of the URL without `?lang`: English (config/app.php).
     */
    public static function defaultLocale(): string
    {
        return (string) config('app.fallback_locale', 'en');
    }

    /**
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_values(config('app.supported_locales', []));
    }

    /**
     * The URL of $url (default: the current page) in $locale.
     */
    public static function for(string $locale, ?string $url = null): string
    {
        $query = [];

        if ($url === null) {
            $url = url()->current();

            foreach (self::KEPT_QUERY as $key) {
                $value = request()->query($key);

                // Page 1 is the list itself.
                if (is_string($value) && $value !== '' && $value !== '1') {
                    $query[$key] = $value;
                }
            }
        }

        // The root of the site is `/`, so `?lang=de` never hangs off the host name.
        if (parse_url($url, PHP_URL_PATH) === null) {
            $url .= '/';
        }

        if ($locale !== self::defaultLocale()) {
            $query[self::QUERY] = $locale;
        }

        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * hreflang => URL for every supported locale, plus `x-default`.
     *
     * @return array<string, string>
     */
    public static function alternates(?string $url = null): array
    {
        $alternates = [];

        foreach (self::locales() as $locale) {
            $alternates[$locale] = self::for($locale, $url);
        }

        $alternates['x-default'] = self::for(self::defaultLocale(), $url);

        return $alternates;
    }
}
