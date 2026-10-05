<?php

namespace App\Support;

/**
 * Foreign avatars through the einundzwanzig-group image proxy (performance
 * plan P5, `config('esports.image_proxy_url')`). The only PHP place that
 * builds proxy URLs; resources/js/imageProxy.js is the browser's twin and
 * picks the same preset for the same size.
 *
 * The proxy has fixed presets: `avatar` is a 96 px square, enough for a
 * picture drawn at up to 48 CSS px on a 2x screen. Larger pictures take
 * `avatar-lg`, a 192 px square (sharp up to 96 CSS px on a 2x screen), the
 * largest ones `msg`.
 * Without a configured proxy, and for anything that is not a foreign https
 * URL (our uploads, the generated Blockpile), the URL comes back unchanged.
 */
final class ImageProxy
{
    /** The largest CSS size the 96 px `avatar` preset serves sharp on a 2x screen. */
    public const SMALL_MAX = 48;

    /** Up to here the 192 px `avatar-lg`; larger pictures (player page, TV, champion) take `msg`, sharp on a 2x screen (user, 2026-10-05). */
    public const MEDIUM_MAX = 96;

    public static function avatar(string $url, int $size = self::SMALL_MAX): string
    {
        $base = (string) config('esports.image_proxy_url');

        if ($base === '' || ! str_starts_with($url, 'https://') || str_starts_with($url, $base.'/') || self::isOwn($url)) {
            return $url;
        }

        $preset = $size <= self::SMALL_MAX ? 'avatar' : ($size <= self::MEDIUM_MAX ? 'avatar-lg' : 'msg');

        return $base.'/'.$preset.'?src='.rawurlencode($url);
    }

    private static function isOwn(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && is_string($appHost) && strcasecmp($host, $appHost) === 0;
    }
}
