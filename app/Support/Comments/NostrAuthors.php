<?php

namespace App\Support\Comments;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\PlayerProfile;

/**
 * Names and pictures for the authors of comments and RSVPs read from relays
 * (P48). A league account shows with the name and picture the league knows
 * and links to its player page (the player card opens on it); anybody else
 * with a short npub and a generated picture, marked as not in the league.
 * Pubkeys are public; this says only who plays here.
 */
final class NostrAuthors
{
    /** Pubkeys looked up in one call. */
    public const BATCH = 100;

    /**
     * @param  array<mixed>  $pubkeys
     * @return array<string, array{name: string, avatar: string, npub: string, href: string|null, player: bool}>
     */
    public static function of(array $pubkeys): array
    {
        $hex = array_values(array_unique(array_filter(array_slice($pubkeys, 0, self::BATCH), fn (mixed $key): bool => NostrKeys::isHexPubkey($key))));

        if ($hex === []) {
            return [];
        }

        $authors = [];

        foreach ($hex as $pubkey) {
            $npub = NostrKeys::hexToNpub($pubkey);
            $authors[$pubkey] = [
                'name' => substr($npub, 0, 10).'…'.substr($npub, -4),
                'avatar' => PlayerProfile::generatedAvatarUrl($pubkey),
                'npub' => $npub,
                'href' => null,
                'player' => false,
            ];
        }

        foreach (User::query()->whereIn('pubkey', $hex)->get() as $user) {
            $authors[(string) $user->pubkey] = [
                'name' => $user->displayName(),
                'avatar' => $user->avatarUrl() ?? PlayerProfile::generatedAvatarUrl((string) $user->pubkey),
                'npub' => (string) $user->npub,
                'href' => route('players.show', $user->npub, false),
                'player' => true,
            ];
        }

        return $authors;
    }
}
