<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Board;
use App\Support\Notifications\DmRelays;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Carbon\CarbonInterface;

/**
 * The tournament desk (user, 2026-10-03: "pro Turnier einen Turnierleiter-Chat
 * auch als ephemeral Chat Nostr-Gruppe, damit alle Spieler mit der
 * Turnierleitung chatten können"): one private NIP-17 group per tournament,
 * run in the browser by resources/js/deskChat.js like the match room chat,
 * never through the league server, which cannot read it and stores nothing.
 *
 * Members, read on every page load (NIP-17 sends to the `p` list of the
 * moment):
 *
 *   - the entrants: the players of every active sign-up and the person who
 *     entered it, and the players of every bracket entry;
 *   - the tournament direction (`manager`): the creator, the named
 *     directors and the league's admins; their messages are marked.
 *
 * NIP-17: "The set of `pubkey` + `p` tags defines a chat room. If a new `p`
 * tag is added or a current one is removed, a new room is created with a
 * clean message history." Other clients (Amethyst) key the group by that set,
 * so each change of the list opens a new, empty conversation there (tournament
 * 2, 2026-10-04: the organizer saw the desk message of 11:06 UTC in Amethyst,
 * not those of 15:00). During sign-up that cannot be helped: an entrant joins
 * or withdraws. From the close on the set holds until the desk closes: the
 * draw keeps the solo pool's reserves in, and a disqualified entry stays a
 * member. That is deliberate: a disqualification during the run must not open
 * a new room for every player, the disqualified player may still ask the
 * direction why, and the bracket shows the entry publicly anyway.
 *
 * Open from sign-up through a day after the end (TournamentLiveSlides::
 * finishedAt()); never for a draft, a called-off tournament or a league week
 * (it has no sign-up and no direction to ask). Only a member gets the config
 * and with it the member list: nobody else learns who is in the group.
 */
final class TournamentDesk
{
    /** The desk stays open this long after the last result. */
    public const HOURS_AFTER_END = 24;

    public static function isOpen(Tournament $tournament, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($tournament->isLeagueWeek()) {
            return false;
        }

        return match ($tournament->status) {
            TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running => true,
            TournamentStatus::Finished => ($finished = TournamentLiveSlides::finishedAt($tournament)) !== null
                && $finished->copy()->addHours(self::HOURS_AFTER_END)->greaterThan($now),
            TournamentStatus::Draft, TournamentStatus::Cancelled => false,
        };
    }

    /**
     * Everybody in the group, entrants first, each once; whoever directs the
     * tournament is marked `manager`, also when they play in it.
     *
     * @return list<array{user: User, pubkey: string, name: string, manager: bool}>
     */
    public static function members(Tournament $tournament): array
    {
        $managerIds = self::managerIds($tournament);
        $ids = array_values(array_unique([...self::entrantIds($tournament), ...$managerIds]));
        $users = User::query()->whereKey($ids)->whereNotNull('pubkey')->get()->keyBy('id');
        $members = [];

        foreach ($ids as $id) {
            $user = $users->get($id);

            if ($user === null || isset($members[$user->pubkey])) {
                continue;
            }

            $members[$user->pubkey] = ['user' => $user, 'pubkey' => $user->pubkey, 'name' => $user->displayName(), 'manager' => in_array($id, $managerIds, true)];
        }

        return array_values($members);
    }

    public static function isMember(Tournament $tournament, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array($user->id, self::managerIds($tournament), true) || in_array($user->id, self::entrantIds($tournament), true);
    }

    /**
     * The desk for this viewer: the chat config of resources/js/deskChat.js,
     * or null while it is closed or the viewer is no member.
     *
     * @return array<string, mixed>|null
     */
    public static function for(Tournament $tournament, ?User $viewer): ?array
    {
        if ($viewer === null || ! filled($viewer->pubkey) || ! self::isOpen($tournament)) {
            return null;
        }

        $members = self::members($tournament);

        if (! in_array($viewer->pubkey, array_column($members, 'pubkey'), true)) {
            return null;
        }

        $managers = array_column(array_filter($members, fn (array $member): bool => $member['manager']), 'pubkey');

        return [
            'me' => $viewer->pubkey,
            'desk' => $tournament->id,
            'url' => route('tournaments.show', $tournament).'#desk',
            'members' => array_map(fn (array $member): array => ['pubkey' => $member['pubkey'], 'name' => $member['name'], 'manager' => $member['manager']], $members),
            'manager' => in_array($viewer->pubkey, $managers, true),
            // A tournament runs for weeks from its sign-up: the chat reads back to the publication.
            'since' => ($tournament->published_at ?? $tournament->created_at)?->getTimestamp(),
            'relays' => array_values(config('esports.chat.relays', [])),
            'lookupRelays' => DmRelays::lookupRelays(),
            // The viewer's mutes, never of the direction: its announcements reach everybody.
            'muted' => array_values(array_diff($viewer->mutedPubkeys(), $managers)),
            'labels' => [
                'you' => __('you'),
                'direction' => __('Tournament direction'),
                'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'failed' => __('That did not work. Please try again.'),
            ],
        ];
    }

    /**
     * The creator, the named directors and every admin (the board from the
     * config and the admins granted in the admin area).
     *
     * @return list<int>
     */
    private static function managerIds(Tournament $tournament): array
    {
        $adminKeys = [...Board::pubkeys(), ...Admin::query()->pluck('pubkey')->all()];

        $ids = [
            ...($tournament->created_by_id === null ? [] : [$tournament->created_by_id]),
            ...$tournament->directors()->pluck('users.id')->all(),
            ...($adminKeys === [] ? [] : User::query()->whereIn('pubkey', $adminKeys)->pluck('id')->all()),
        ];

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * The players of every active sign-up and who entered it, plus the
     * players of every bracket entry, disqualified ones included. Sign-ups
     * are frozen from the close on (TournamentModeration::assertBeforeDraw()),
     * and every bracket entry is drawn from them, so this set no longer
     * changes after the close: a solo player the draw left as a reserve
     * stays, and so does a disqualified entry.
     *
     * @return list<int>
     */
    private static function entrantIds(Tournament $tournament): array
    {
        $signedUp = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->get()
            ->flatMap(fn (TournamentSignup $signup): array => [...array_map(intval(...), $signup->members ?? []), ...($signup->user_id === null ? [] : [$signup->user_id])])->all();
        $drawn = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get()
            ->flatMap(fn (TournamentParticipant $entry): array => $entry->memberIds())->all();

        return array_values(array_unique(array_map(intval(...), [...$signedUp, ...$drawn])));
    }
}
