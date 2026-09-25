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

    public const KIND_LIVE_ACTIVITY = 30311;

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

    /**
     * Kind 30311 (NIP-53 live activity) for our own self-hosted stream.
     *
     * Client rules this enforces (zapstream.md A.1-A.7): exactly one
     * `streaming` tag, its URL ending in `.m3u8` with nothing after it, and
     * the `p` tag with all four elements (Primal reads `t[3]` of every `p`).
     *
     * @param  array{d: string, title: string, summary: string, image: string, t?: list<string>}  $stream
     */
    public function liveActivity(array $stream, string $streamingUrl, string $hostPubkey, string $status, int $starts, ?int $ends = null): Event
    {
        if (! self::isStreamingUrl($streamingUrl)) {
            throw new InvalidArgumentException('The streaming URL must end in .m3u8: '.$streamingUrl);
        }

        if (! in_array($status, ['planned', 'live', 'ended'], true)) {
            throw new InvalidArgumentException('Unknown NIP-53 status: '.$status);
        }

        $tags = [
            ['d', $stream['d']],
            ['title', $stream['title']],
            ['summary', $stream['summary']],
            ['image', $stream['image']],
            ['status', $status],
            ['starts', (string) $starts],
        ];

        if ($ends !== null) {
            $tags[] = ['ends', (string) $ends];
        }

        $tags[] = ['streaming', $streamingUrl];

        foreach ($stream['t'] ?? [] as $topic) {
            $tags[] = ['t', $topic];
        }

        $tags[] = ['p', $hostPubkey, '', 'host'];

        return $this->event(self::KIND_LIVE_ACTIVITY)->setTags($tags);
    }

    /**
     * An http(s) URL whose path ends in `.m3u8` with nothing after it: the
     * zap.stream player only uses hls.js for `endsWith(".m3u8")`.
     */
    public static function isStreamingUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/?\#]+/[^\s?\#]*\.m3u8$#', $url) === 1;
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
