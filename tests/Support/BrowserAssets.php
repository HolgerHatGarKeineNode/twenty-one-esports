<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Vite;
use RuntimeException;

/**
 * Serves the browser tests' built assets through /__test/assets/
 * (routes/testing.php), which sends them with a cache lifetime. The
 * plugin's in-process server sends public/build/* with no caching headers,
 * so every navigation fetched every script, stylesheet and font again
 * through the one PHP process.
 */
final class BrowserAssets
{
    public const PREFIX = '/__test/assets/';

    private const FONTS_MANIFEST = 'fonts-manifest.browser-tests.json';

    public static function use(): void
    {
        Vite::createAssetPathsUsing(fn (string $path): string => self::PREFIX.$path);

        // @fonts inlines the @font-face rules with absolute /build/ URLs, which
        // the asset path above never sees: a copy of the manifest carries the
        // same rules pointing here. Rebuilt whenever the build is newer.
        $source = public_path('build/fonts-manifest.json');

        if (! is_file($source)) {
            return;
        }

        $target = public_path('build/'.self::FONTS_MANIFEST);

        if (! is_file($target) || filemtime($target) < filemtime($source)) {
            $manifest = json_decode((string) file_get_contents($source), true);

            if (! is_array($manifest) || ! is_array($manifest['style'] ?? null)) {
                throw new RuntimeException('Unexpected fonts manifest shape in '.$source);
            }

            $rewrite = fn (string $css): string => str_replace('url("/build/', 'url("'.self::PREFIX.'build/', $css);
            $style = $manifest['style'];

            if (isset($style['file'])) {
                $style['inline'] = $rewrite((string) file_get_contents(public_path('build/'.$style['file'])));
                unset($style['file']);
            }

            $style['familyStyles'] = array_map($rewrite, $style['familyStyles'] ?? []);
            $manifest['style'] = $style;
            file_put_contents($target, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        Vite::useFontsManifestFilename(self::FONTS_MANIFEST);
    }
}
