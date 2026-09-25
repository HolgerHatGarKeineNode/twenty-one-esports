<?php

namespace App\Support\Nostr;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;

/**
 * Checks a NIP-98-style login event (kind 27235) against the request.
 *
 * The event carries `u` (absolute URL of the login endpoint), `method` and a
 * `challenge` issued by {@see LoginChallenges}. Checks run cheapest first and
 * the Schnorr signature last; the challenge is only consumed once everything
 * else passed, so a forged attempt cannot burn a real user's challenge.
 */
final class NostrLogin
{
    /** NIP-98 HTTP Auth. */
    public const KIND = 27235;

    /** Allowed distance between created_at and the server clock (NIP-98 suggests 60 s). */
    public const MAX_CLOCK_SKEW_SECONDS = 60;

    public function __construct(private LoginChallenges $challenges) {}

    /**
     * The verified event, or null when the login must be refused.
     */
    public function verify(mixed $input, string $url, string $method, Session $session): ?SignedEvent
    {
        $event = SignedEvent::fromInput($input);

        if ($event === null) {
            return $this->reject('malformed event');
        }

        if ($event->kind !== self::KIND) {
            return $this->reject('wrong kind', $event);
        }

        if (abs(now()->getTimestamp() - $event->createdAt) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $this->reject('created_at outside window', $event);
        }

        if ($event->tag('u') !== $url || strtoupper((string) $event->tag('method')) !== strtoupper($method)) {
            return $this->reject('url or method mismatch', $event);
        }

        $challenge = $event->tag('challenge');

        if ($challenge === null || ! $this->challenges->isPending($session, $challenge)) {
            return $this->reject('unknown or expired challenge', $event);
        }

        if (! $event->hasValidSignature()) {
            return $this->reject('invalid signature', $event);
        }

        if (! $this->challenges->consume($session, $challenge)) {
            return $this->reject('challenge already used', $event);
        }

        return $event;
    }

    private function reject(string $reason, ?SignedEvent $event = null): null
    {
        Log::info('Nostr login refused: '.$reason, ['pubkey' => $event?->pubkey]);

        return null;
    }
}
