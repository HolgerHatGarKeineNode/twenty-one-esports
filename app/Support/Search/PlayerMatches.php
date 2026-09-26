<?php

namespace App\Support\Search;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which players a typed term can mean, for the player picker
 * (PlayerSearchController) and the site search (SearchController): a full
 * npub or hex key, an npub prefix of at least NPUB_PREFIX characters after
 * "npub1", or a display name or stored NIP-05 of at least MIN_TERM
 * characters. LIKE wildcards in the term are escaped, so "%" finds a "%".
 *
 * Cost: a full key is a unique-index lookup and an npub prefix a range on
 * the unique npub index. A name or NIP-05 is a LIKE over the players; the
 * callers cap the rows.
 */
final class PlayerMatches
{
    public const MIN_TERM = 2;

    public const NPUB_PREFIX = 8;

    /** A pasted profile link stands for its npub. */
    public static function normalize(string $term): string
    {
        $term = trim($term);

        return str_contains($term, '/') ? (string) preg_replace('#^.*/(npub1[0-9a-z]+).*$#', '$1', $term) : $term;
    }

    /**
     * The players the term can mean, or null when it is too short to search.
     *
     * @return Builder<User>|null
     */
    public static function query(string $term): ?Builder
    {
        $pubkey = NostrKeys::toHex($term);

        if ($pubkey !== null) {
            return User::query()->where('pubkey', $pubkey);
        }

        $lower = mb_strtolower($term);

        if (str_starts_with($lower, 'npub1')) {
            // bech32 only: '~' sorts after every bech32 character, so the range is exactly the prefix.
            return strlen($lower) - 5 >= self::NPUB_PREFIX && preg_match('/^npub1[02-9ac-hj-np-z]+$/', $lower) === 1
                ? User::query()->where('npub', '>=', $lower)->where('npub', '<', $lower.'~')->orderBy('npub')
                : null;
        }

        if (mb_strlen($term) < self::MIN_TERM) {
            return null;
        }

        $like = self::escapeLike($lower);

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw("lower(name) like ? escape '!'", ['%'.$like.'%'])
                ->orWhereRaw("nip05 like ? escape '!'", [$like.'%']))
            ->orderByRaw("case when lower(name) like ? escape '!' then 0 else 1 end", [$like.'%'])
            ->orderBy('name');
    }

    /** The term for a LIKE with `escape '!'`: its own !, % and _ match only themselves. */
    public static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
