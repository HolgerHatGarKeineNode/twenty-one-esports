<?php

namespace App\Support\Nostr;

use App\Jobs\VerifyNip05;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Caches a kind-0 profile (NIP-01, NIP-24): name, picture, about, banner,
 * website, Lightning address (lud16) and NIP-05 address.
 *
 * The server never connects to a relay for this. Browsers read kind 0 from
 * `esports.profile_relays` and hand the SIGNED events in: at login the
 * player's own (apply()), on any page the profiles of the players shown there
 * (acceptBatch()). Only a profile signed by a known player's key is taken,
 * and only one newer than the cached version; everything else is dropped
 * silently, it never blocks a login or a page.
 *
 * Cheap checks run first, Schnorr last (see SignedEvent).
 */
final class ProfileCache
{
    public const MAX_CONTENT_BYTES = 65536;

    /** Most events one batch may carry (one relay REQ's worth of authors). */
    public const MAX_BATCH = 50;

    /**
     * A profile dated further ahead than this is refused: a far-future
     * created_at would pin the cache, since only newer versions replace it.
     */
    public const MAX_AHEAD_SECONDS = 600;

    private const URL_MAX = 2048;

    /**
     * The logged-in player's own profile, sent along with the login event.
     */
    public static function apply(User $user, mixed $input): void
    {
        $event = SignedEvent::fromInput($input);

        if ($event === null || $event->pubkey !== $user->pubkey) {
            return;
        }

        self::store($user, $event);
    }

    /**
     * Profiles of any known players, as a page's browser read them from the
     * profile relays. Returns the players whose cache changed.
     *
     * @param  array<mixed>  $inputs
     * @return Collection<int, User>
     */
    public static function acceptBatch(array $inputs): Collection
    {
        // Newest well-formed kind 0 per pubkey, before any database or Schnorr work.
        $newest = [];

        foreach (array_slice(array_values($inputs), 0, self::MAX_BATCH) as $input) {
            $event = SignedEvent::fromInput($input);

            if ($event === null || $event->kind !== 0) {
                continue;
            }

            if (! isset($newest[$event->pubkey]) || $event->createdAt > $newest[$event->pubkey]->createdAt) {
                $newest[$event->pubkey] = $event;
            }
        }

        if ($newest === []) {
            return collect();
        }

        return User::query()
            ->whereIn('pubkey', array_keys($newest))
            ->get()
            ->filter(fn (User $user): bool => self::store($user, $newest[$user->pubkey]))
            ->values();
    }

    /**
     * Whether the page should ask relays for this player's profile again.
     */
    public static function isStale(User $user): bool
    {
        $checked = $user->profile_checked_at;

        return $checked === null
            || $checked->getTimestamp() < now()->subMinutes((int) config('esports.profiles.ttl_minutes', 360))->getTimestamp();
    }

    /**
     * Checks one event against one player and caches it. True when the cached
     * profile changed.
     */
    private static function store(User $user, SignedEvent $event): bool
    {
        $cachedAt = $user->profile_event_at?->getTimestamp();

        if ($event->kind !== 0
            || $event->pubkey !== $user->pubkey
            || strlen($event->content) > self::MAX_CONTENT_BYTES
            || $event->createdAt > now()->getTimestamp() + self::MAX_AHEAD_SECONDS
            || ($cachedAt !== null && $event->createdAt < $cachedAt)
        ) {
            return false;
        }

        $metadata = json_decode($event->content, true);

        if (! is_array($metadata) || ! $event->hasValidSignature()) {
            return false;
        }

        // The same version again: the cache is confirmed current, nothing changes.
        if ($cachedAt !== null && $event->createdAt === $cachedAt) {
            $user->forceFill(['profile_checked_at' => now()])->save();

            return false;
        }

        $nip05 = self::nip05($metadata['nip05'] ?? null);
        $nip05Changed = $nip05 !== $user->nip05;

        $user->forceFill([
            'name' => self::name($metadata),
            'picture' => self::httpsUrl($metadata['picture'] ?? null),
            'about' => self::text($metadata['about'] ?? null, 1000),
            'banner' => self::httpsUrl($metadata['banner'] ?? null),
            'website' => self::httpsUrl($metadata['website'] ?? null),
            'lud16' => self::lud16($metadata['lud16'] ?? null),
            'nip05' => $nip05,
            'nip05_verified_at' => $nip05Changed ? null : $user->nip05_verified_at,
            'nip05_checked_at' => $nip05Changed ? null : $user->nip05_checked_at,
            'profile_event_at' => Carbon::createFromTimestamp($event->createdAt),
            'profile_checked_at' => now(),
        ])->save();

        if ($nip05 !== null && ($nip05Changed || Nip05Verifier::isDue($user))) {
            VerifyNip05::dispatch($user);
        }

        return true;
    }

    /**
     * @param  array<mixed>  $metadata
     */
    private static function name(array $metadata): ?string
    {
        foreach (['display_name', 'name'] as $field) {
            $value = self::text(is_string($metadata[$field] ?? null) ? str_replace(["\r", "\n"], ' ', $metadata[$field]) : null, 100, '');

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Trimmed, cut to $limit, without control characters (newlines stay) and
     * without bidi overrides, which could flip the text around them. Joiners
     * stay: emoji sequences need them.
     */
    private static function text(mixed $value, int $limit, string $end = '…'): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x{0}-\x{9}\x{B}-\x{1F}\x{7F}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value));

        return $value === '' ? null : Str::limit($value, $limit, $end);
    }

    /**
     * Only https, only well-formed: a picture or banner is loaded straight
     * from its host by every visitor, and a website is a link.
     */
    private static function httpsUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > self::URL_MAX || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_HOST) !== null ? $url : null;
    }

    /**
     * A Lightning address, `name@domain` (LUD-16). Shown, never paid, here.
     */
    private static function lud16(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return strlen($value) <= 320 && preg_match('/^[a-z0-9._+-]+@([a-z0-9-]+\.)+[a-z]{2,}$/', $value) === 1 ? $value : null;
    }

    /**
     * A NIP-05 address, `local@domain` with the local part NIP-05 allows
     * (a-z0-9-_.), lowercased. Its truth is checked later ({@see Nip05Verifier}).
     */
    private static function nip05(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return strlen($value) <= 320 && preg_match('/^[a-z0-9._-]+@[^@\s\/?#]+$/', $value) === 1 ? $value : null;
    }
}
