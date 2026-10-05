/**
 * Foreign avatars through the einundzwanzig-group image proxy (performance
 * plan P5). The browser's twin of App\Support\ImageProxy: the same presets
 * for the same sizes, and the only JavaScript that builds proxy URLs.
 *
 * The base comes from <meta name="image-proxy"> (partials/head, only when
 * `esports.image_proxy_url` is set). Without it, and for anything that is not
 * a foreign https URL (our uploads, the generated Blockpile), the URL comes
 * back unchanged. A proxy that refuses a picture (403/502) answers with an
 * error, so the caller's onerror fallback to the Blockpile still fires.
 *
 * Presets as in PHP: `avatar` (96 px square) up to 48 CSS px, `avatar-lg`
 * (192 px square) up to 96, `msg` above (sharp large pictures on a 2x screen).
 */
const SMALL_MAX = 48;
const MEDIUM_MAX = 96;

let base;

function proxyBase() {
    if (base === undefined) {
        base = (document.querySelector('meta[name="image-proxy"]')?.content || '').replace(/\/+$/, '');
    }

    return base;
}

/**
 * @param {string|null|undefined} url the picture's own URL
 * @param {number} size the CSS size it is drawn at
 * @returns {string|null|undefined}
 */
export function proxiedAvatar(url, size = SMALL_MAX) {
    const proxy = proxyBase();

    if (! proxy || typeof url !== 'string' || ! url.startsWith('https://') || url.startsWith(proxy + '/')) {
        return url;
    }

    try {
        if (new URL(url).origin === window.location.origin) {
            return url;
        }
    } catch {
        return url;
    }

    return `${proxy}/${size <= SMALL_MAX ? 'avatar' : size <= MEDIUM_MAX ? 'avatar-lg' : 'msg'}?src=${rawUrlEncode(url)}`;
}

/**
 * PHP's rawurlencode(): encodeURIComponent() leaves !'()* as they are, and the
 * proxy caches by the exact src, so a differing spelling is a second cache entry.
 *
 * @param {string} value
 * @returns {string}
 */
function rawUrlEncode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, (char) => '%' + char.charCodeAt(0).toString(16).toUpperCase());
}
