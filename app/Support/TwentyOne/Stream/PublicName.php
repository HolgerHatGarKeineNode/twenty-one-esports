<?php

namespace App\Support\TwentyOne\Stream;

use App\Models\User;
use App\Support\Nostr\Nip05Verifier;
use App\Support\Nostr\NostrKeys;

/**
 * A player's public name made safe for the stream: the scene picture and the
 * 30311 title/summary both go through here.
 *
 * Other code points (\p{C}: control characters, bidi overrides such as
 * U+202E, zero-width marks, unassigned) are removed; any run of whitespace,
 * newlines included, becomes one space. Nothing is escaped here: that is the
 * job of the output (Blade for the SVG, JSON for the event).
 */
final class PublicName
{
    public static function clean(string $name): string
    {
        $name = preg_replace('/\p{C}+/u', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    /**
     * The name a slide shows for a player, read live: the profile name, else
     * the verified NIP-05, else the short npub (`npub1…k7q2`). Never the
     * truncated npub User::displayName() falls back to.
     */
    public static function of(User $user): string
    {
        $name = self::clean((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        if ($user->nip05 !== null && $user->nip05_verified_at !== null) {
            return self::clean(Nip05Verifier::display($user->nip05));
        }

        return $user->shortNpub();
    }

    /**
     * A key without an account as the short npub (`npub1…k7q2`): the npub's
     * last four characters, not the hex key's. A malformed key: `npub1…`.
     */
    public static function ofKey(string $pubkey): string
    {
        return strlen($pubkey) === 64 && ctype_xdigit($pubkey) ? 'npub1…'.substr(NostrKeys::hexToNpub($pubkey), -4) : 'npub1…';
    }

    /**
     * Every npub in a text, whole or cut short (User::displayName()'s `npub1qy3k8wz…` fallback, a name frozen from
     * it), as the bare `npub1…`; the short form `npub1…k7q2` of of() stays as it is. For the public broadcast paths
     * (the league feed, the overlay snapshot), whose names come from many readers: none of them may put a
     * searchable part of a Nostr identity next to a result.
     */
    public static function maskNpubs(string $text): string
    {
        return preg_replace('/npub1[02-9ac-hj-np-z]+…?/u', 'npub1…', $text) ?? '';
    }

    /**
     * clean(), then at most `$max` code points: longer names keep `$max - 1`
     * and end in "…".
     */
    public static function limit(string $name, int $max): string
    {
        $name = self::clean($name);

        return mb_strlen($name) <= $max ? $name : rtrim(mb_substr($name, 0, $max - 1)).'…';
    }
}
