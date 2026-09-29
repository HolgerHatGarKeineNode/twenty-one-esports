<?php

namespace App\Support\Notifications;

use Normalizer;

/**
 * Text written by someone else (a challenge message, a kind-0 display name, a
 * clan name) as it may appear in a notification DM: one line of plain text
 * without anything a Nostr client could turn into a link.
 *
 * - NFKC first, so fullwidth letters and look-alike dots (U+FF0E, U+2024)
 *   become ASCII before the link check; the ideographic full stops U+3002
 *   and U+FF61, which NFKC leaves alone, are mapped to "." too. Only the
 *   ellipsis is kept as it is ("12… Nf6", a shortened npub).
 * - Control characters and line/paragraph separators become a space, so no
 *   foreign text can start a line of its own (and fake the opt-out line).
 *   Format characters (bidi overrides, zero-width spaces) are dropped; the
 *   joiners and emoji tag characters that emoji sequences need stay.
 * - Links of any form are cut out: `scheme://…`, the schemes used without
 *   slashes, bare Nostr and Lightning bech32 strings of full length (the
 *   shortened `npub1abcdefg…` a nameless player is shown as stays), every
 *   LNURL whatever its length (a short one still encodes a whole URL), `www.…`, bare domains
 *   (`name.tld`, the TLD not followed by a letter or digit, so `2.Nf3` stays)
 *   and bare IPv4 addresses with an optional port or path.
 *
 * A notification's own title and body name players and clans in it, and a
 * name shaped like a domain ("octavia.dickens") would vanish from the DM
 * while the bell shows it. With `$names` a bare domain is therefore not cut
 * but defused: each of its dots becomes a middle dot ("octavia·dickens"),
 * which no client reads as a link and nobody mistakes for one. Every other
 * link form is still cut, and free text a player wrote (a challenge
 * message) is cleaned without `$names` before it is put in, so a domain in
 * it stays cut.
 */
final class PlainText
{
    /** A bare domain (`name.tld`, optionally with a path), the last form LINK cuts. */
    private const DOMAIN = '[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*\.\p{L}{2,}(?![\p{L}\p{N}_-])(?:[/:?#]\S*)?';

    private const LINK = '~(?:\b[a-z][a-z0-9+.-]*://\S+'
        .'|\b(?:nostr|web\+nostr|mailto|lightning|bitcoin|magnet|tel|sms|data|javascript):\S+'
        .'|\b(?:npub|nprofile|note|nevent|naddr|nsec|nrelay|lnbc|lntb)1[02-9ac-hj-np-z]{50,}'
        .'|\blnurl1[02-9ac-hj-np-z]+'
        .'|\bwww\.\S+'
        .'|\b\d{1,3}(?:\.\d{1,3}){3}(?::\d{1,5})?(?:/\S*)?'
        .'|'.self::DOMAIN.')~iu';

    /**
     * @param  bool  $names  defuse a bare domain instead of cutting it (the league's own notice text, which names players)
     */
    public static function line(string $text, ?int $limit = null, bool $names = false): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        // The ellipsis stays one character (NFKC would make it three dots).
        $text = Normalizer::normalize(str_replace("\u{2026}", "\u{E000}", $text), Normalizer::FORM_KC);
        $text = str_replace(["\u{3002}", "\u{FF61}", "\u{E000}"], ['.', '.', "\u{2026}"], is_string($text) ? $text : '');
        $text = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text);
        $text = (string) preg_replace('/(?![\x{200C}\x{200D}\x{E0020}-\x{E007F}])\p{Cf}/u', '', $text);
        $text = (string) preg_replace_callback(self::LINK, fn (array $link): string => $names && preg_match('~^'.self::DOMAIN.'$~iu', $link[0]) === 1 && stripos($link[0], 'www.') !== 0
            ? str_replace('.', "\u{00B7}", $link[0])
            : '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $limit === null ? $text : mb_substr($text, 0, $limit);
    }
}
