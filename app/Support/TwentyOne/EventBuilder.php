<?php

namespace App\Support\TwentyOne;

use InvalidArgumentException;
use swentel\nostr\Event\Event;

/**
 * Unsigned events for the TWENTY ONE Esports account.
 *
 * Every method returns a fresh {@see Event} (its setTags() appends, so an
 * event is never reused), ready for {@see TwentyOneSigner::sign()} and
 * {@see RelayPublisher::publish()}.
 */
final class EventBuilder
{
    public const KIND_PROFILE = 0;

    public const KIND_RELAY_LIST = 10002;

    /**
     * Kind 0: the profile as JSON content. Empty or non-string values are
     * left out, so an unset lud16 does not appear as `"lud16":null`.
     *
     * @param  array<string, mixed>  $profile
     */
    public function profile(array $profile): Event
    {
        $fields = array_filter($profile, fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return $this->event(self::KIND_PROFILE)
            ->setContent(json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Kind 10002 (NIP-65): one `r` tag per relay, read and write.
     *
     * @param  list<string>  $relays
     */
    public function relayList(array $relays): Event
    {
        $tags = [];

        foreach (array_unique($relays) as $relay) {
            if (! self::isRelayUrl($relay)) {
                throw new InvalidArgumentException('Not a relay URL: '.$relay);
            }

            $tags[] = ['r', $relay];
        }

        return $this->event(self::KIND_RELAY_LIST)->setTags($tags);
    }

    public static function isRelayUrl(mixed $url): bool
    {
        return is_string($url) && preg_match('#^wss?://[^\s/]+#', $url) === 1;
    }

    private function event(int $kind): Event
    {
        return (new Event)
            ->setKind($kind)
            ->setCreatedAt(now()->getTimestamp());
    }
}
