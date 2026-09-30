<?php

namespace App\Support\GameChat;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\PlayerProfile;
use App\Support\Nostr\SignerMessages;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamChat\StreamChat;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;

/**
 * The global chat of each game (P21, NIP "Game channels"): one NIP-28 public
 * channel per game on the chat relays, with NIP-88 polls in it.
 *
 * A channel is the id of its kind-40 event, and that id is a hash of the
 * event without its signature: creator pubkey, `created_at`, kind, tags and
 * content. All of them are fixed here, so every server with the creator's
 * pubkey computes the same id without the secret, and the chat works before
 * the kind 40 is on any relay; `esports:game-channels` signs and publishes
 * it (and the kind-41 metadata with the relays) with the league key. The
 * content of a kind 40 must never change: that would be another channel.
 * Name, about and relays change through kind 41.
 *
 * The creator is `esports.game_chat.creator` (npub or hex) when set, else the
 * league key's pubkey. Without either there is no channel, and the page says
 * the chat is off.
 *
 * The board games (nine men's morris, checkers; NIP rev. 9.15) have their
 * channels on the same terms, with the same `CREATED_AT`, but only while the
 * board game is switched on (`esports.board_games`, GameRegistry::isBoard()):
 * a switched-off board game shows no chat and the command signs nothing for
 * it. Its id is fixed all the same, so switching it on later opens exactly
 * the channel computed here.
 */
final class GameChannels
{
    /**
     * 2026-09-28T00:00:00Z: the day P21 started. Part of every channel id, the
     * board games' too (rev. 9.15): one fixed value for all channels, so a
     * client computes every id from the creator and the name alone.
     */
    public const CREATED_AT = 1790553600;

    /** Kind-40 names, frozen with the ids (never the registry's display name, which may change). */
    public const GAMES = [
        'chess' => 'Chess',
        'rocket-league' => 'Rocket League',
        'ea-sports-fc-26' => 'EA Sports FC 26',
        'ea-sports-fc-27' => 'EA Sports FC 27',
        // Rev. 9.15: the board games, each only while switched on (has()).
        'nine-mens-morris' => 'Nine Men\'s Morris',
        'checkers' => 'Checkers',
    ];

    /**
     * Whether the game's channel is open: shown on its page and published. A
     * board game's only while it is switched on (the registry has it); the
     * others always.
     */
    public static function has(string $game): bool
    {
        if (! array_key_exists($game, self::GAMES)) {
            return false;
        }

        return ! in_array($game, BoardGame::RESERVED_SLUGS, true) || app(GameRegistry::class)->isBoard($game);
    }

    /**
     * The games whose channel is open now, in the order of GAMES.
     *
     * @return list<string>
     */
    public static function open(): array
    {
        return array_values(array_filter(array_keys(self::GAMES), self::has(...)));
    }

    /** The channel creator's pubkey (hex), null when none is configured. */
    public static function creator(): ?string
    {
        $configured = config('esports.game_chat.creator');

        if (is_string($configured) && trim($configured) !== '') {
            return NostrKeys::toHex(trim($configured));
        }

        return LeagueKey::fromConfig()?->pubkey();
    }

    /** The kind-40 content: name and about, keys in this order, no relays (those go in kind 41). */
    public static function createContent(string $game): string
    {
        $name = self::GAMES[$game] ?? throw new \InvalidArgumentException("No channel for [{$game}].");

        return (string) json_encode([
            'name' => 'TWENTY ONE esports · '.$name,
            'about' => 'The global chat of '.$name.' in the TWENTY ONE esports league: talk and vote.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The unsigned kind 40 of a game's channel, with its id. Fixed by the
     * creator and the game alone, whether or not the channel is open (has()).
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string}|null
     */
    public static function createEvent(string $game, ?string $creator = null): ?array
    {
        $creator ??= self::creator();

        if (! array_key_exists($game, self::GAMES) || ! NostrKeys::isHexPubkey($creator)) {
            return null;
        }

        $content = self::createContent($game);
        $event = (new Event)->setKind(40)->setTags([])->setContent($content)->setCreatedAt(self::CREATED_AT);
        $event->setPublicKey($creator);

        return [
            'id' => hash('sha256', (string) Sign::serializeEvent($event)),
            'pubkey' => $creator,
            'created_at' => self::CREATED_AT,
            'kind' => 40,
            'tags' => [],
            'content' => $content,
        ];
    }

    /** The channel id of a game (hex), null without a creator. */
    public static function channelId(string $game): ?string
    {
        return self::createEvent($game)['id'] ?? null;
    }

    /**
     * The kind-41 metadata: the same name and about plus the relays to read
     * and post on, pointing at the channel with a NIP-10 root `e` tag.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string}|null
     */
    public static function metadataTemplate(string $game): ?array
    {
        $id = self::channelId($game);

        if ($id === null) {
            return null;
        }

        $relays = self::relays();
        $meta = json_decode(self::createContent($game), true);

        return [
            'kind' => 41,
            'tags' => [['e', $id, $relays[0] ?? '', 'root']],
            'content' => (string) json_encode([...(array) $meta, 'relays' => $relays], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * The chat relays (`esports.chat.relays`), valid websocket URLs only.
     *
     * @return list<string>
     */
    public static function relays(): array
    {
        return array_values(array_filter(RelayPublisher::relayUrls(config('esports.chat.relays', [])), fn (string $relay): bool => EventBuilder::isRelayUrl($relay)));
    }

    /**
     * The config for gameChannel() in the browser (resources/js/gameChannel.js),
     * null when the game has no open channel or there is no creator.
     *
     * @return array<string, mixed>|null
     */
    public static function config(string $game, ?User $viewer): ?array
    {
        $creator = self::creator();
        $channel = self::has($game) ? self::createEvent($game, $creator) : null;

        if ($channel === null || $creator === null) {
            return null;
        }

        $relays = self::relays();
        $self = $viewer instanceof User ? (self::players([$viewer->pubkey])[$viewer->pubkey] ?? null) : null;

        return [
            'game' => $game,
            'channel' => $channel['id'],
            'creator' => $creator,
            'relays' => $relays,
            'relayHint' => $relays[0] ?? '',
            'bot' => StreamChat::botPubkey(),
            'me' => $viewer?->pubkey,
            'meName' => $viewer?->displayName(),
            // Whether the viewer's own polls are shown and votes counted (players()); the page says so before they try.
            'meCounts' => $self['counts'] ?? false,
            'meAvatar' => $self['avatar'] ?? null,
            'muted' => $viewer instanceof User ? $viewer->mutedPubkeys() : [],
            'maxLength' => (int) config('esports.game_chat.max_length', 280),
            'cooldownMs' => (int) config('esports.game_chat.cooldown_ms', 2000),
            'history' => (int) config('esports.game_chat.history', 120),
            'poll' => [
                'questionMax' => (int) config('esports.game_chat.poll.question_max', 140),
                'optionMax' => (int) config('esports.game_chat.poll.option_max', 60),
                'maxOptions' => (int) config('esports.game_chat.poll.max_options', 4),
                'durations' => array_values(array_map(intval(...), (array) config('esports.game_chat.poll.durations', [86400]))),
            ],
            'avatarUrl' => route('avatars.generated', ['pubkey' => StreamChat::AVATAR_PLACEHOLDER, 'v' => 1], false),
            'avatarPlaceholder' => StreamChat::AVATAR_PLACEHOLDER,
            'emojiRelays' => RelayPublisher::relayUrls([...RelayPublisher::relayUrls(config('esports.profile_relays', [])), ...$relays]),
            'locale' => app()->getLocale(),
            'labels' => [
                ...SignerMessages::labels(),
                'bot' => __('bot'),
                'league' => __('league'),
                'notPlayer' => __('not in the league'),
                'mute' => __('Mute :name'),
                'unmute' => __('Unmute :name'),
                'mutedOne' => __('1 message from a muted account'),
                'mutedMany' => __(':count messages from muted accounts'),
                'show' => __('Show'),
                'hide' => __('Hide'),
                'newMessages' => __(':count new'),
                'newMessage' => __('1 new'),
                'tooLong' => __('Keep it to :max characters.'),
                'wait' => __('One message every 2 seconds. Try again in a moment.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'someone' => __('Someone'),
                'insert' => __('Insert :emoji'),
                'yourEmoji' => __('Your emoji'),
                'votes' => __(':count votes'),
                'vote' => __('1 vote'),
                'closesIn' => __('closes :time'),
                'closed' => __('closed'),
                'yourVote' => __('your vote'),
                'uncounted' => __(':count votes not counted: no result in the league'),
                'uncountedOne' => __('1 vote not counted: no result in the league'),
                'pollInvalid' => __('A poll needs a question and 2 to 4 different answers.'),
                'voteNotSent' => __('Your vote did not reach any relay. Please try again.'),
                'notCountedYet' => __('Your vote is shown to you but counts once you have a result in the league.'),
            ],
        ];
    }

    /**
     * Name, avatar and vote weight of the league accounts among `pubkeys` (at
     * most 100, hex only). The chat shows an account by its league name; its
     * polls are shown and its votes counted only when `counts` is true: the
     * account is a paying member (`is_member`), or it has a result in the
     * league, on its own rating or on a lineup it holds an accepted seat in
     * (`ratings.results` of at least 1, casual or rated). An account alone is
     * one Nostr login away; a result takes an opponent and a finished game.
     * Pubkeys are public; this says only who plays here.
     *
     * @param  array<mixed>  $pubkeys
     * @return array<string, array{name: string, avatar: string, counts: bool}>
     */
    public static function players(array $pubkeys): array
    {
        $hex = array_values(array_unique(array_filter(array_slice($pubkeys, 0, 100), fn (mixed $key): bool => NostrKeys::isHexPubkey($key))));

        if ($hex === []) {
            return [];
        }

        $users = User::query()->whereIn('pubkey', $hex)->get();
        $ids = $users->modelKeys();
        $played = Rating::query()->whereIn('user_id', $ids)->where('results', '>', 0)->distinct()->pluck('user_id')->all();
        $seated = LineupSeat::query()->whereIn('user_id', $ids)->whereNotNull('accepted_at')
            ->whereIn('lineup_id', Rating::query()->whereNotNull('lineup_id')->where('results', '>', 0)->select('lineup_id'))
            ->distinct()->pluck('user_id')->all();
        $counting = array_flip([...$played, ...$seated]);
        $players = [];

        foreach ($users as $user) {
            $players[$user->pubkey] = [
                'name' => $user->displayName(),
                'avatar' => $user->avatarUrl() ?? PlayerProfile::generatedAvatarUrl($user->pubkey),
                'counts' => $user->is_member || isset($counting[$user->id]),
            ];
        }

        return $players;
    }
}
