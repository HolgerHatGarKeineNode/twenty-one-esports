<?php

namespace App\Support\Nostr;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Caches name and picture from a kind-0 profile (NIP-01, NIP-24) that the
 * login module fetched from relays and sent along.
 *
 * The server never connects to a relay for this; instead it only accepts a
 * profile signed by the same key that just logged in, and only one newer
 * than what is cached. A forged or foreign profile is ignored silently: it
 * never blocks the login.
 */
final class ProfileCache
{
    private const MAX_CONTENT_BYTES = 65536;

    public static function apply(User $user, mixed $input): void
    {
        $event = SignedEvent::fromInput($input);

        if ($event === null
            || $event->kind !== 0
            || $event->pubkey !== $user->pubkey
            || strlen($event->content) > self::MAX_CONTENT_BYTES
            || ($user->profile_event_at !== null && $event->createdAt <= $user->profile_event_at->getTimestamp())
            || ! $event->hasValidSignature()
        ) {
            return;
        }

        $metadata = json_decode($event->content, true);

        if (! is_array($metadata)) {
            return;
        }

        $user->forceFill([
            'name' => self::name($metadata),
            'picture' => self::picture($metadata['picture'] ?? null),
            'profile_event_at' => Carbon::createFromTimestamp($event->createdAt),
        ])->save();
    }

    /**
     * @param  array<mixed>  $metadata
     */
    private static function name(array $metadata): ?string
    {
        foreach (['display_name', 'name'] as $field) {
            $value = $metadata[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 100, '');
            }
        }

        return null;
    }

    private static function picture(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
