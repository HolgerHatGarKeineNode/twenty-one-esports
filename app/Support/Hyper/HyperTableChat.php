<?php

namespace App\Support\Hyper;

use App\Models\HyperMatch;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\SignerMessages;
use App\Support\StreamChat\StreamChat;

/**
 * The table chat of a Hyperbitcoinization match for the browser (plan "Hyperbitcoinization", P2, Ansatz 6):
 * the match's NIP-28 channel (GameChannels::matchChannelId()) on the league's chat relays, players and
 * spectators in one channel (user, 2026-10-08), with reactions (NIP-25). resources/js/hyper/chat.js reads
 * and writes it straight from the browser, on the game channels' terms (GameChannels::config()): the same
 * creator, relays, limits and moderation. `channel` is null without a creator, and the page says the chat is
 * off; so it does without relays.
 */
final class HyperTableChat
{
    /**
     * @return array<string, mixed>
     */
    public static function config(HyperMatch $match, ?User $viewer): array
    {
        $relays = GameChannels::relays();
        $self = $viewer instanceof User ? (GameChannels::players([$viewer->pubkey])[$viewer->pubkey] ?? null) : null;

        return [
            'channel' => GameChannels::matchChannelId($match->ulid),
            'creator' => GameChannels::creator(),
            'relays' => $relays,
            'relayHint' => $relays[0] ?? '',
            'me' => $viewer?->pubkey,
            'meName' => $viewer?->displayName(),
            'meAvatar' => $self['avatar'] ?? null,
            // Keys an admin muted or banned site-wide and the viewer's own mutes: left out of the list and the counts.
            'hidden' => SiteModeration::leftOutFor($viewer),
            'maxLength' => (int) config('esports.game_chat.max_length', 280),
            'cooldownMs' => (int) config('esports.game_chat.cooldown_ms', 2000),
            'history' => (int) config('esports.game_chat.history', 120),
            'avatarUrl' => route('avatars.generated', ['pubkey' => StreamChat::AVATAR_PLACEHOLDER, 'v' => 1], false),
            'avatarPlaceholder' => StreamChat::AVATAR_PLACEHOLDER,
            'peopleUrl' => route('hyper.people', absolute: false),
            'locale' => app()->getLocale(),
            'signer' => SignerMessages::labels(),
        ];
    }

    /**
     * Name, avatar and vote weight of the league accounts among these pubkeys (at most 100): how the table chat
     * names whoever writes, as the game channels do, and whether a vote in the spectators' poll counts (P5,
     * HyperPoll: `counts`, as in a game channel's poll). Only what the league shows anyway; never a gamer tag.
     *
     * @param  array<mixed>  $pubkeys
     * @return array<string, array{name: string, avatar: string, counts: bool}>
     */
    public static function people(array $pubkeys): array
    {
        return array_map(
            fn (array $player): array => ['name' => $player['name'], 'avatar' => $player['avatar'], 'counts' => $player['counts']],
            GameChannels::players($pubkeys),
        );
    }
}
