<?php

namespace App\Support;

use App\Support\Cards\PageCard;
use App\Support\Seo\SearchIndexing;
use Closure;
use Throwable;

/**
 * Search, link-preview and robots tags of the current page (P6b, P14),
 * printed by partials/head.blade.php: a page sets them while it renders,
 * before the layout's <head> is written. One instance per request (bound to
 * the request in AppServiceProvider), so nothing leaks from one request into
 * the next, under Octane, in a queue worker or in a test.
 *
 * The tags are plain HTML in the first response: messengers and crawlers
 * (Signal, Telegram, Nostr clients, search engines) read them without
 * running JavaScript.
 *
 * Indexing is opt-in: a page that never calls describe() is `noindex`, so a
 * new private page cannot end up in a search index by forgetting a line.
 * Outside production on the APP_URL host every page is `noindex`.
 * A public page calls describe() with its own title and description and
 * gets the canonical URL, the hreflang alternates and the preview tags.
 *
 * Structured data: a page adds its JSON-LD nodes with addStructuredData();
 * the Organization node is printed on every page by the head itself. A
 * published tournament adds App\Support\Seo\StructuredData::tournament().
 */
final class PageMeta
{
    public ?string $description = null;

    /**
     * Preview images in order of preference: [url, width, height, alt].
     *
     * @var list<array{0: string, 1: int, 2: int, 3: string}>
     */
    public array $images = [];

    /** The page's own URL (og:url, canonical); null = the current URL. */
    public ?string $url = null;

    public bool $noindex = false;

    public ?string $title = null;

    /**
     * JSON-LD nodes of the page, each a complete schema.org object.
     *
     * @var list<array<string, mixed>>
     */
    public array $structuredData = [];

    /**
     * Marks the page as public and indexable, with its own title and description.
     */
    public function describe(string $title, string $description): self
    {
        $this->title = $title;
        $this->description = $description;

        return $this;
    }

    /**
     * The page's link preview (P54): its own card, one 1200 × 630 PNG per
     * page and state; the URL carries the state, so a link shared after a
     * change shows the new picture. Reading the card's facts never breaks the
     * page: on any error the preview is the brand card.
     *
     * @param  Closure(): PageCard  $card
     */
    public function card(Closure $card): self
    {
        try {
            $built = $card();
            $this->images = [[$built->url(), PageCard::WIDTH, PageCard::HEIGHT, $built->alt()]];
        } catch (Throwable $e) {
            report($e);
            $this->images = [self::brandImage()];
        }

        return $this;
    }

    /**
     * The brand card: the static 1200 × 630 picture a page without a card of its own shows.
     *
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    public static function brandImage(): array
    {
        return [asset('images/og/fallback.png'), PageCard::WIDTH, PageCard::HEIGHT, 'TWENTY ONE esports'];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function addStructuredData(array $node): self
    {
        $this->structuredData[] = $node;

        return $this;
    }

    /**
     * Search engines may index the page: it described itself, is not private,
     * and this is the production site on its own host (SearchIndexing).
     */
    public function isIndexable(): bool
    {
        return ! $this->noindex && $this->description !== null && SearchIndexing::allowed();
    }
}
