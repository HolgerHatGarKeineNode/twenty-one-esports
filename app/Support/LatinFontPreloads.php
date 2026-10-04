<?php

namespace App\Support;

/**
 * `@fonts` preloads only the latin files of the webfonts (performance plan
 * P5, F12). laravel-vite-plugin preloads every WOFF2 of a preloaded weight,
 * latin-ext included (Unbounded 118 KB of 169 KB), although the browser
 * fetches a unicode-range file only when the page uses one of its glyphs.
 * The latin-ext files stay in the @font-face rules and load on demand.
 *
 * A preload resolver for Vite::usePreloadTagAttributes(): `false` drops the
 * tag. Only a file the fonts manifest names with a range that does not start
 * at U+0000 is dropped; an unknown file (dev server, missing manifest) keeps
 * its preload, which costs bytes, never a missing font.
 */
final class LatinFontPreloads
{
    /** @var array<string, true>|null basenames of the non-latin WOFF2 files */
    private static ?array $extended = null;

    /**
     * @return array<string, string>|false
     */
    public static function resolve(string $src, string $url): array|false
    {
        if ($src !== 'fonts') {
            return [];
        }

        return isset(self::extended()[basename((string) parse_url($url, PHP_URL_PATH))]) ? false : [];
    }

    /**
     * @return array<string, true>
     */
    private static function extended(): array
    {
        if (self::$extended !== null) {
            return self::$extended;
        }

        $path = public_path('build/fonts-manifest.json');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $extended = [];

        foreach (is_array($manifest) ? (array) ($manifest['families'] ?? []) : [] as $family) {
            foreach ((array) ($family['variants'] ?? []) as $variant) {
                foreach ((array) ($variant['files'] ?? []) as $file) {
                    $range = (string) ($file['unicodeRange'] ?? '');

                    if (isset($file['file']) && $range !== '' && ! str_starts_with($range, 'U+0000')) {
                        $extended[basename((string) $file['file'])] = true;
                    }
                }
            }
        }

        return self::$extended = $extended;
    }
}
