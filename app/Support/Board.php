<?php

namespace App\Support;

use App\Support\Nostr\NostrKeys;

/**
 * The board npubs mirrored in `config('esports.board')`, as hex pubkeys.
 *
 * Fail closed: an entry that is not a valid npub is dropped instead of being
 * passed through, so a typo in the config denies instead of matching
 * something unintended.
 */
final class Board
{
    /**
     * @return list<string>
     */
    public static function pubkeys(): array
    {
        $pubkeys = [];

        foreach ((array) config('esports.board', []) as $npub) {
            $hex = is_string($npub) ? NostrKeys::npubToHex($npub) : null;

            if ($hex !== null) {
                $pubkeys[] = $hex;
            }
        }

        return $pubkeys;
    }

    public static function contains(string $pubkey): bool
    {
        return in_array($pubkey, self::pubkeys(), true);
    }
}
