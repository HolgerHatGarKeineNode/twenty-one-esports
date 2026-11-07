<?php

namespace App\Support\Caster;

use Illuminate\Support\Facades\Http;

/**
 * The real articles of the discover feed (user 2026-10-16: "echte Tutorial-Artikel aus
 * discover.einundzwanzig.space/feed.xml - keine erfundenen Dinge mehr"): a cached fetch, at
 * most once per hour, the last good copy kept when the feed fails - the pattern of
 * PodcastFeed. Each article carries its title, author, day and body as data for the
 * timeless tutorial programme (StockScripts).
 */
final class DiscoverArticles
{
    private const CACHE = 'caster:discover_feed';

    /** @var list<array{title: string, author: string, day: string, body: string, url: string}>|null */
    private static ?array $items = null;

    /**
     * The newest articles, at most `limit`, newest first; null when nothing could be read.
     *
     * @return list<array{title: string, author: string, day: string, body: string, url: string}>|null
     */
    public static function newest(int $limit = 12): ?array
    {
        $items = self::items();

        return $items === null ? null : array_slice($items, 0, max(1, $limit));
    }

    /**
     * A random article the tutorial of this hour has not used yet (remembered by url), null when none is left.
     *
     * @return array{title: string, author: string, day: string, body: string, url: string}|null
     */
    public static function pick(string $rememberKey = 'caster:stock:discover_used', int $remember = 24): ?array
    {
        $items = self::items();

        if ($items === null) {
            return null;
        }

        $used = array_flip(array_slice(array_reverse((array) cache()->get($rememberKey, [])), -$remember));
        $fresh = array_values(array_filter($items, fn (array $item): bool => ! isset($used[$item['url']])));

        if ($fresh === []) {
            $fresh = $items;
        }

        $article = $fresh[array_rand($fresh)];
        $list = array_slice([...array_reverse((array) cache()->get($rememberKey, [])), $article['url']], -$remember);
        cache()->put($rememberKey, array_values(array_reverse($list)), now()->addDays(7));

        // The feed names the articles, the page carries the text (only the newest item has a description):
        // fetch it once per article, keep it a day, and fall back to whatever the feed gave.
        $article['body'] = self::pageBody($article['url']) ?? $article['body'];

        return $article;
    }

    /**
     * The article page's own text (its main content, tags stripped), at most ~1400 characters, cached a day.
     */
    private static function pageBody(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $key = 'caster:discover_page:'.sha1($url);
        $cached = cache()->get($key);

        if (is_string($cached)) {
            return $cached !== '' ? $cached : null;
        }

        try {
            $response = Http::timeout(10)->withHeaders(['User-Agent' => 'twentyone-caster/1.0'])->get($url);
        } catch (\Throwable) {
            cache()->put($key, '', now()->addHours(6));

            return null;
        }

        if (! $response->successful()) {
            cache()->put($key, '', now()->addHours(6));

            return null;
        }

        $html = (string) $response->body();
        $body = preg_match('/<article[^>]*>(.*?)<\/article>/is', $html, $m) === 1 ? $m[1]
            : (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $m) === 1 ? $m[1] : $html);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace('/\s*(Die EU ist eine|Starlink).*$/u', '', $text) ?? $text; // nothing of the site's chrome
        $cut = mb_substr($text, 0, 1400);

        cache()->put($key, $cut, now()->addDay());

        return $cut !== '' ? $cut : null;
    }

    /**
     * @return list<array{title: string, author: string, day: string, body: string, url: string}>|null
     */
    private static function items(): ?array
    {
        if (self::$items !== null) {
            return self::$items;
        }

        $cached = self::cached(cache()->get(self::CACHE));

        if ($cached !== null) {
            return self::$items = $cached;
        }

        try {
            $response = Http::timeout((int) config('tv.commentary.chat.podcast_timeout_seconds', 8))
                ->withHeaders(['User-Agent' => 'twentyone-caster/1.0'])
                ->get((string) config('tv.commentary.chat.discover_feed'));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $xml = (string) $response->body();

        if (strlen($xml) > (int) config('tv.commentary.chat.podcast_max_bytes', 12 * 1024 * 1024)) {
            return null;
        }

        $items = [];
        foreach (explode('<item>', $xml) as $index => $chunk) {
            if ($index === 0 || ! str_contains($chunk, '</item>')) {
                continue;
            }

            $item = substr($chunk, 0, (int) strpos($chunk, '</item>') ?: strlen($chunk));
            $text = fn (string $tag): string => preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $item, $m) === 1
                ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $creator = preg_match('/<dc:creator>(.*?)<\/dc:creator>/s', $item, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]))) : '';
            $url = preg_match('/<link>(.*?)<\/link>/s', $item, $m) === 1 ? trim($m[1]) : '';
            $published = preg_match('/<pubDate>(.*?)<\/pubDate>/s', $item, $m) === 1 ? strtotime($m[1]) : false;
            $title = $text('title');

            if ($title === '') {
                continue;
            }

            $items[] = [
                'title' => mb_substr($title, 0, 160),
                'author' => mb_substr($creator, 0, 80),
                'day' => $published === false ? '' : PodcastFeed::spokenDay((int) $published),
                // The description is the article's own teaser; the full text stays on the page.
                'body' => mb_substr($text('description'), 0, (int) config('tv.commentary.chat.podcast_chars', 600)),
                'url' => $url,
            ];
        }

        if ($items === []) {
            return null;
        }

        cache()->put(self::CACHE, $items, now()->addMinutes((int) config('tv.commentary.chat.podcast_minutes', 60)));

        return self::$items = $items;
    }

    /**
     * The cached feed items when every entry carries the five strings items() writes; null for anything else, so a
     * cache entry of another shape is fetched anew instead of read.
     *
     * @return list<array{title: string, author: string, day: string, body: string, url: string}>|null
     */
    private static function cached(mixed $cached): ?array
    {
        if (! is_array($cached) || ! array_is_list($cached)) {
            return null;
        }

        $items = [];
        foreach ($cached as $item) {
            if (! is_array($item) || ! is_string($item['title'] ?? null) || ! is_string($item['author'] ?? null) || ! is_string($item['day'] ?? null)
                || ! is_string($item['body'] ?? null) || ! is_string($item['url'] ?? null)) {
                return null;
            }

            $items[] = ['title' => $item['title'], 'author' => $item['author'], 'day' => $item['day'], 'body' => $item['body'], 'url' => $item['url']];
        }

        return $items;
    }
}
