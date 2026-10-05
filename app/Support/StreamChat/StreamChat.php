<?php

namespace App\Support\StreamChat;

use App\Models\User;
use App\Support\Lightning\Lnurl;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignerMessages;
use App\Support\Prizes\PoolInvoices;
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
     * The newest receipt time each legacy signer may still have: the shared
     * getalby key signs for every Alby user, so only receipts from before the
     * profile left it count. The league key has no limit.
     *
     * @return array<string, int>
     */
    public static function zapUntil(): array
    {
        $until = (int) config('esports.stream_chat.zap_signers_until', 0);
        $limits = [];

        foreach ((array) config('esports.stream_chat.zap_signers', []) as $pubkey) {
            if (NostrKeys::isHexPubkey($pubkey) && $until > 0) {
                $limits[$pubkey] = $until;
            }
        }

        return $limits;
    }

    /**
     * The LNURL servers whose zap receipts show, each with the LNURL (LUD-01,
     * lowercase bech32) a zap request it receipted must name when it names
     * one: the league's own server (`pool@<host>`, the profile's lud16 since
     * 2026-10-03) and the old ones in `esports.stream_chat.zap_signers`
     * (getalby, `zap_signers_lud16`), so their receipts still show.
     *
     * @return array<string, string|null> signer pubkey (hex) => its LNURL, null when unknown
     */
    public static function zapLnurls(): array
    {
        $old = config('esports.stream_chat.zap_signers_lud16');
        $oldLnurl = Lnurl::fromAddress(is_string($old) ? $old : null);
        $lnurls = [];

        foreach ((array) config('esports.stream_chat.zap_signers', []) as $pubkey) {
            if (NostrKeys::isHexPubkey($pubkey)) {
                $lnurls[$pubkey] = $oldLnurl;
            }
        }

        $league = LeagueKey::lnurl()?->pubkey();

        if ($league !== null) {
            $lnurls[$league] = strtolower(PoolInvoices::lnurl());
        }

        return $lnurls;
    }

    /**
     * @return list<string>
     */
    public static function zapSigners(): array
    {
        return array_keys(self::zapLnurls());
    }

    /**
     * Whom a zap of the stream pays: `esports.stream_chat.zap_recipient`
     * (hex or npub) when set, else the pool key (whose profile's lud16 is the
     * league's `pool@<host>`), else the stream key (the 30311's host). A
     * receipt and its request must both name it as `p`.
     */
    public function zapRecipient(): string
    {
        $configured = config('esports.stream_chat.zap_recipient');
        $hex = is_string($configured) && trim($configured) !== '' ? NostrKeys::toHex(trim($configured)) : null;

        return $hex ?? LeagueKey::poolPubkey() ?? $this->stream->pubkey;
    }

    /**
     * The player page of a mentioned league account, `NPUB` standing for its npub (the browser fills it in).
     */
    public static function playerUrl(): string
    {
        return route('players.show', ['npub' => 'NPUB'], false);
    }

    /**
     * Which of these pubkeys (at most 100, hex only) are league accounts: a
     * NIP-27 mention of one links to its player page, anybody else to
     * njump.me. Only the pubkeys come back, nothing about the accounts; the
     * player pages say as much already.
     *
     * @param  array<mixed>  $pubkeys
     * @return list<string>
     */
    public static function players(array $pubkeys): array
    {
        $hex = array_values(array_unique(array_filter(array_slice($pubkeys, 0, 100), fn (mixed $key): bool => NostrKeys::isHexPubkey($key))));

        if ($hex === []) {
            return [];
        }

        // The asked keys that have an account, in the order asked.
        return array_values(array_intersect($hex, User::query()->whereIn('pubkey', $hex)->pluck('pubkey')->all()));
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
            // Where the relay lists (10002) of people the profile relays do not know are read (NIP-65 outbox).
            'indexerRelays' => RelayPublisher::relayUrls(config('esports.stream_chat.indexer_relays', [])),
            'playerUrl' => self::playerUrl(),
            // NIP-30 lists (10030, 30030) usually sit where the profile does.
            'emojiRelays' => RelayPublisher::relayUrls([...$profileRelays, ...$this->relays]),
            'bot' => self::botPubkey(),
            'zapSigners' => self::zapSigners(),
            'zapRecipient' => $this->zapRecipient(),
            'zapLnurls' => self::zapLnurls(),
            'zapUntil' => self::zapUntil(),
            'me' => $viewer?->pubkey,
            'meName' => $viewer?->displayName(),
            'muted' => $viewer instanceof User ? $viewer->mutedPubkeys() : [],
            // Keys an admin muted or banned site-wide: their messages and zaps are left out for everyone (SiteModeration).
            'hidden' => SiteModeration::hiddenFor($viewer),
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
                'mute' => __('Mute :name for me'),
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
                'quoted' => __('Quoted message'),
                'insert' => __('Insert :emoji'),
                'yourEmoji' => __('Your emoji'),
                'recent' => __('Recently used'),
            ],
        ];
    }
}
