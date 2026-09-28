<?php

namespace App\Support\Cards;

/**
 * One share post before it is signed (NIP "Share posts", rev. 8 and 9.7):
 * the sentence, the card it shows and what it refers to. Built by
 * {@see SharePosts} from a moment of the player; the note itself is
 * {@see SharePosts::template()}.
 *
 * - `mentions`: the players the note names with `nostr:npub…` and a `p`
 *   (NIP-27), so their clients show the mention: the opponents of a win.
 * - `quote`: one event of the league the note quotes with `nostr:nevent…` or
 *   `nostr:naddr…` and a `q` (NIP-18): the league's game record, the result
 *   report of a series, a tournament's calendar event.
 * - `link`: the page the note links (an `r`), and for an "I'm in" post the
 *   tournament's page, which is the invite.
 */
final readonly class SharePost
{
    /**
     * @param  string  $sentence  the plain sentence: the preview's first line, the card's `alt`
     * @param  string  $cardUrl  the card on this site, absolute, with its `v`
     * @param  array{0: int, 1: int}  $dimensions  of the card at `$cardUrl`
     * @param  string|null  $storyPath  the 1080 × 1920 story card, for the download; null when there is none
     * @param  list<array{pubkey: string, name: string}>  $mentions
     * @param  array{ref: string, relay: string, pubkey: string|null, uri: string, what: string}|null  $quote
     */
    public function __construct(
        public string $type,
        public string $sentence,
        public string $cardUrl,
        public array $dimensions,
        public ?string $storyPath,
        public string $link,
        public array $mentions = [],
        public ?array $quote = null,
        public bool $linkInSentence = false,
    ) {}

    /** The card's path on this site, for the page's own preview and download. */
    public function cardPath(): string
    {
        return substr($this->cardUrl, strlen(rtrim((string) config('app.url'), '/')));
    }
}
