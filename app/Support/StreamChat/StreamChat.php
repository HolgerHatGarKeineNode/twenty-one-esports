<?php

namespace App\Support\StreamChat;

use App\Models\User;
use App\Support\Lightning\Lnurl;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignerMessages;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;

/**
 * What the /live page hands its chat (P24, resources/js/liveChat.js): the
 * stream's NIP-53 address, the relays to read and post on, who the bot is,
 * whose zap receipts count, and the viewer's own key and mutes.
 *
 * Nothing here asks a relay. Without a stream (no key, no `d`) there is no
 * chat at all; without a relay the chat says it is off.
 */
final readonly class StreamChat
{
    public const AVATAR_PLACEHOLDER = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  list<string>  $relays
     */
    public function __construct(
        public StreamCoordinates $stream,
        public array $relays,
    ) {}

    public static function current(): ?self
    {
        $stream = StreamCoordinates::fromConfig();

        return $stream === null ? null : new self($stream, self::relaysFor($stream));
    }

    /**
     * `esports.stream_chat.relays` when set (empty = off), else the stream's
     * relays and the bot's chat relays: the bot posts on the first, people
     * who chat through zap.stream or Amethyst mostly on the second.
     *
     * @return list<string>
     */
    public static function relaysFor(StreamCoordinates $stream): array
    {
        $configured = config('esports.stream_chat.relays');
        $relays = is_array($configured)
            ? RelayPublisher::relayUrls($configured)
            : RelayPublisher::relayUrls([...$stream->relays, ...(array) config('esports.stream_bot.chat_relays', [])]);

        return array_values(array_filter($relays, fn (string $relay): bool => EventBuilder::isRelayUrl($relay)));
    }

    /**
     * The bot's pubkey (P22), so its posts carry a "bot" mark; null without a bot key.
     */
    public static function botPubkey(): ?string
    {
        return once(fn (): ?string => LeagueKey::streamBot()?->pubkey());
    }

    /**
     * @return list<string>
     */
    public static function zapSigners(): array
    {
        return array_values(array_filter((array) config('esports.stream_chat.zap_signers', []), fn (mixed $pubkey): bool => NostrKeys::isHexPubkey($pubkey)));
    }

    /**
     * Whom a zap of the stream pays: `esports.stream_chat.zap_recipient`
     * (hex or npub) when set, else the stream key (the 30311's host). A
     * receipt and its request must both name it as `p`.
     */
    public function zapRecipient(): string
    {
        $configured = config('esports.stream_chat.zap_recipient');
        $hex = is_string($configured) && trim($configured) !== '' ? NostrKeys::toHex(trim($configured)) : null;

        return $hex ?? $this->stream->pubkey;
    }

    /**
     * The recipient's LNURL (LUD-01, lowercase bech32 `lnurl`) from the stream's
     * lud16 (`twentyone.nostr.lud16`, LUD-16: `https://<domain>/.well-known/lnurlp/<user>`).
     * A zap request that names an `lnurl` must name this one. Null without a lud16.
     */
    public static function zapLnurl(): ?string
    {
        $lud16 = config('twentyone.nostr.lud16');

        return Lnurl::fromAddress(is_string($lud16) ? $lud16 : null);
    }

    /**
     * The config for liveChat() in the browser.
     *
     * @return array<string, mixed>
     */
    public function config(?User $viewer): array
    {
        $profileRelays = RelayPublisher::relayUrls(config('esports.profile_relays', []));

        return [
            'address' => $this->stream->address(),
            'relayHint' => $this->relays[0] ?? $this->stream->relayHint(),
            'relays' => $this->relays,
            'profileRelays' => $profileRelays,
            // NIP-30 lists (10030, 30030) usually sit where the profile does.
            'emojiRelays' => RelayPublisher::relayUrls([...$profileRelays, ...$this->relays]),
            'bot' => self::botPubkey(),
            'zapSigners' => self::zapSigners(),
            'zapRecipient' => $this->zapRecipient(),
            'zapLnurl' => self::zapLnurl(),
            'me' => $viewer?->pubkey,
            'meName' => $viewer?->displayName(),
            'muted' => $viewer instanceof User ? $viewer->mutedPubkeys() : [],
            'maxLength' => (int) config('esports.stream_chat.max_length', 280),
            'cooldownMs' => (int) config('esports.stream_chat.cooldown_ms', 2000),
            'history' => max(1, (int) config('esports.stream_chat.history', 50)),
            // The Blockpile avatar of a pubkey without a picture: the browser swaps this placeholder key for it.
            'avatarUrl' => route('avatars.generated', ['pubkey' => self::AVATAR_PLACEHOLDER, 'v' => 1], false),
            'locale' => app()->getLocale(),
            'labels' => [
                ...SignerMessages::labels(),
                'bot' => __('bot'),
                'newMessages' => __(':count new'),
                'newMessage' => __('1 new'),
                'mute' => __('Mute :name'),
                'unmute' => __('Unmute :name'),
                'mutedOne' => __('1 message from a muted account'),
                'mutedMany' => __(':count messages from muted accounts'),
                'show' => __('Show'),
                'hide' => __('Hide'),
                'zapped' => __(':amount sats'),
                'tooLong' => __('Keep it to :max characters.'),
                'wait' => __('One message every 2 seconds. Try again in a moment.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'someone' => __('Someone'),
                'insert' => __('Insert :emoji'),
                'yourEmoji' => __('Your emoji'),
                'recent' => __('Recently used'),
            ],
        ];
    }
}
