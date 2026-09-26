<?php

namespace App\Support;

/**
 * Link-preview and robots tags of the current page (P6b), printed by
 * partials/head.blade.php: a page sets them while it renders, before the
 * layout's <head> is written. One instance per request (scoped binding), so
 * nothing leaks from one request into the next under Octane or a queue worker.
 *
 * The tags are plain HTML in the first response: messengers and crawlers
 * (Signal, Telegram, Nostr clients) read them without running JavaScript.
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

    public ?string $url = null;

    public bool $noindex = false;

    public ?string $title = null;

    public function isEmpty(): bool
    {
        return $this->description === null && $this->images === [] && ! $this->noindex;
    }
}
