<?php

namespace App\Support\Hyper;

use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignerMessages;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;

/**
 * The spectators' "Who wins?" of a Hyperbitcoinization match (plan "Hyperbitcoinization", P5, Ansatz 7): a NIP-88
 * poll (kind 1068) in the match's table chat channel, on the game channels' terms (NIP rev. 9.3), for a rated or a
 * tournament match only.
 *
 * Like the channel itself, the poll is fixed by the match alone: the channel creator, the match's start as
 * `created_at`, the channel's root `e`, one answer per seat (`s<seat>`, "Seat 1 · Bitcoiner") or per team in a
 * team match (`t<team>`, "Team 1"), `singlechoice`, and an `endsAt` `esports.hyper.poll_days` after the start. So
 * its id is known without the secret and the page counts votes (kind 1018, signed by each voter) before the league
 * has published the poll, if it ever does (`esports.hyper.publish`, HyperPublisher::poll()). The labels never name
 * a player: a name can change, the poll cannot. The page shows the players' names next to them.
 *
 * Only spectators see it ({@see visibleTo()}): never a player of the match, not even one who left. The page closes
 * it when the match ends: a vote signed after the end is not counted, whatever `endsAt` says.
 */
final class HyperPoll
{
    public const QUESTION = 'Who wins?';

    /** The factions' names in an answer label: English and fixed, as the poll is. */
    private const FACTIONS = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => 'ECB', 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];

    /** Whether the match has the poll at all: a rated or a tournament match, with a channel creator. */
    public static function has(HyperMatch $match): bool
    {
        return ($match->rated || $match->tournament_match_id !== null) && NostrKeys::isHexPubkey(GameChannels::creator());
    }

    /** Whether this viewer sees the poll: a spectator (a guest too) of a match that has one, never a player. */
    public static function visibleTo(HyperMatch $match, ?User $viewer): bool
    {
        return self::has($match) && $match->seatOf($viewer) === null;
    }

    /**
     * The unsigned poll with its id, or null for a match without one.
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string}|null
     */
    public static function event(HyperMatch $match): ?array
    {
        $creator = GameChannels::creator();
        $channel = GameChannels::matchChannelId($match->ulid);

        if (! self::has($match) || $channel === null || ! NostrKeys::isHexPubkey($creator) || $match->created_at === null) {
            return null;
        }

        $createdAt = $match->created_at->getTimestamp();
        $tags = [['e', $channel, '', 'root']];

        foreach (self::answers($match) as $id => $label) {
            $tags[] = ['option', $id, $label];
        }

        $tags[] = ['polltype', 'singlechoice'];
        $tags[] = ['endsAt', (string) self::endsAt($match)];

        $event = (new Event)->setKind(1068)->setTags($tags)->setContent(self::QUESTION)->setCreatedAt($createdAt);
        $event->setPublicKey($creator);

        return [
            'id' => hash('sha256', (string) Sign::serializeEvent($event)),
            'pubkey' => $creator,
            'created_at' => $createdAt,
            'kind' => 1068,
            'tags' => $tags,
            'content' => self::QUESTION,
        ];
    }

    /**
     * The answers as the poll fixes them: `s<seat>` => "Seat 1 · Bitcoiner", or in a team match `t<team>` => "Team 1".
     *
     * @return array<string, string>
     */
    public static function answers(HyperMatch $match): array
    {
        $match->loadMissing('seats');

        if ($match->isTeamMatch()) {
            return ['t0' => 'Team 1', 't1' => 'Team 2'];
        }

        $answers = [];

        foreach ($match->seats as $seat) {
            $answers['s'.$seat->seat] = 'Seat '.($seat->seat + 1).' · '.(self::FACTIONS[$seat->faction] ?? $seat->faction);
        }

        return $answers;
    }

    /**
     * What the match page's poll card needs (resources/js/hyper/poll.js), for a spectator; null for anybody else.
     * `closedAt` is the match's end (null while it runs): votes after it are not counted. `players` are the
     * pubkeys of the seats, whose votes never count. `options` carry the label the viewer reads: the player's
     * name (or the bot's) and the faction, the team's clan.
     *
     * @return array<string, mixed>|null
     */
    public static function config(HyperMatch $match, ?User $viewer): ?array
    {
        $event = self::visibleTo($match, $viewer) ? self::event($match) : null;

        if ($event === null) {
            return null;
        }

        $match->loadMissing('seats.user');
        $labels = $match->isTeamMatch() ? self::teamLabels($match) : $match->seats->mapWithKeys(fn (HyperSeat $seat): array => [
            // The factions are names; only the ECB has a German one (EZB), as on the start page.
            's'.$seat->seat => ($seat->user?->displayName() ?? __('Bot')).' · '.($seat->faction === 'ezb' ? __('ECB') : (self::FACTIONS[$seat->faction] ?? $seat->faction)),
        ])->all();

        return [
            'id' => $event['id'],
            'created_at' => $event['created_at'],
            'endsAt' => self::endsAt($match),
            'closedAt' => $match->ended_at?->getTimestamp(),
            'options' => array_map(fn (string $id): array => ['id' => $id, 'label' => $labels[$id] ?? $id], array_keys(self::answers($match))),
            'players' => $match->seats->map(fn (HyperSeat $seat): ?string => $seat->user?->pubkey)->filter()->values()->all(),
            'me' => $viewer?->pubkey,
            'signer' => SignerMessages::labels(),
        ];
    }

    /** `endsAt`: the start plus `esports.hyper.poll_days` of the match's mode (the page closes it at the end before). */
    private static function endsAt(HyperMatch $match): int
    {
        return (int) $match->created_at?->getTimestamp() + max(1, (int) config('esports.hyper.poll_days.'.$match->mode, 7)) * 86400;
    }

    /**
     * @return array<string, string>
     */
    private static function teamLabels(HyperMatch $match): array
    {
        $clans = Clan::query()->whereKey(array_filter((array) $match->team_clans))->pluck('name', 'id');
        $labels = [];

        foreach ([0, 1] as $team) {
            $clan = $match->team_clans[$team] ?? null;
            $labels['t'.$team] = $clan !== null && isset($clans[$clan]) ? (string) $clans[$clan] : __('Team :number', ['number' => $team + 1]);
        }

        return $labels;
    }
}
