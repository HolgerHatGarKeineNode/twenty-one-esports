<?php

namespace App\Support\StreamBot;

use App\Support\Nostr\NostrKeys;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;

/**
 * Where the 24/7 stream's kind-30311 event lives, read from the config the
 * stream daemon (TwentyOneStreamCommand) publishes it with: the stream key
 * (`twentyone.nostr.npub`, or the public key of `twentyone.nostr.nsec` when
 * no npub is set), the `d` tag (`twentyone.stream.event.d`) and the relays
 * (`twentyone.stream.relays`). Read only; the daemon stays the only writer.
 */
final readonly class StreamCoordinates
{
    /**
     * @param  list<string>  $relays
     */
    public function __construct(
        public string $pubkey,
        public string $d,
        public array $relays,
    ) {}

    /**
     * Null when the stream has no key, no `d` or no relay: then there is no
     * stream to talk in.
     */
    public static function fromConfig(): ?self
    {
        $d = config('twentyone.stream.event.d');
        $relays = self::relays();
        $pubkey = self::streamPubkey();

        if (! is_string($d) || trim($d) === '' || $relays === [] || $pubkey === null) {
            return null;
        }

        return new self($pubkey, $d, $relays);
    }

    /**
     * The stream relays (`twentyone.stream.relays`), valid websocket URLs
     * only: where the bot publishes its profile and its notes too.
     *
     * @return list<string>
     */
    public static function relays(): array
    {
        return array_values(array_filter(
            RelayPublisher::relayUrls(config('twentyone.stream.relays')),
            fn (string $relay): bool => EventBuilder::isRelayUrl($relay),
        ));
    }

    /** NIP-53/NIP-01 address of the live event: `30311:<pubkey>:<d>`. */
    public function address(): string
    {
        return EventBuilder::KIND_LIVE_ACTIVITY.':'.$this->pubkey.':'.$this->d;
    }

    /** The relay hint of the `a` tag: the first stream relay. */
    public function relayHint(): string
    {
        return $this->relays[0];
    }

    private static function streamPubkey(): ?string
    {
        $npub = config('twentyone.nostr.npub');

        if (is_string($npub) && trim($npub) !== '') {
            return NostrKeys::npubToHex(trim($npub));
        }

        return TwentyOneSigner::fromNsec(config('twentyone.nostr.nsec'))?->pubkey;
    }
}
