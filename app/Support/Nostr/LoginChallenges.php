<?php

namespace App\Support\Nostr;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Session\Session;

/**
 * Server-issued, single-use login challenges.
 *
 * Two stores, two jobs. The session remembers which challenges were issued
 * to THIS browser, so a signed login event captured elsewhere cannot be
 * replayed from another session (login CSRF, relayed events). The cache
 * holds the one-time marker: consuming it is an atomic `add`, so two
 * concurrent submissions of the same event cannot both pass.
 */
final class LoginChallenges
{
    /** Seconds a challenge may be used after it was issued. */
    public const TTL_SECONDS = 300;

    /** Pending challenges per session, so a second tab does not break the first. */
    private const MAX_PER_SESSION = 5;

    private const SESSION_KEY = 'nostr_login.challenges';

    public function __construct(private Cache $cache) {}

    public function issue(Session $session): string
    {
        $challenge = bin2hex(random_bytes(32));
        $now = now()->getTimestamp();

        $pending = array_filter(
            $this->pending($session),
            static fn (int $expiresAt): bool => $expiresAt > $now,
        );
        $pending[$challenge] = $now + self::TTL_SECONDS;

        $session->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PER_SESSION, preserve_keys: true));

        return $challenge;
    }

    /**
     * Was this challenge issued to this session and is it still unexpired?
     * Does not consume it.
     */
    public function isPending(Session $session, string $challenge): bool
    {
        $expiresAt = $this->pending($session)[$challenge] ?? null;

        return $expiresAt !== null && $expiresAt > now()->getTimestamp();
    }

    /**
     * Use the challenge up. True exactly once per challenge.
     */
    public function consume(Session $session, string $challenge): bool
    {
        if (! $this->isPending($session, $challenge)) {
            return false;
        }

        $pending = $this->pending($session);
        unset($pending[$challenge]);
        $session->put(self::SESSION_KEY, $pending);

        return $this->cache->add('nostr-login:used:'.$challenge, true, self::TTL_SECONDS);
    }

    /**
     * @return array<string, int>
     */
    private function pending(Session $session): array
    {
        $pending = $session->get(self::SESSION_KEY, []);

        return is_array($pending) ? array_filter($pending, 'is_int') : [];
    }
}
