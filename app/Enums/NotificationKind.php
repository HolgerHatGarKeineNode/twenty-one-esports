<?php

namespace App\Enums;

use App\Models\BoardGame;
use App\Models\ChessGame;

/**
 * Every event the league tells a player about (P5c). Adding a case here is
 * the whole registration: the settings page lists it as a switch
 * (ChessSettings::triggers()), the bell stores it, and the page it arrives on
 * shows the toast, plays the sound and flashes the tab title.
 *
 * The value is the settings key and the `kind` of the stored notification,
 * so it must never change once shipped.
 */
enum NotificationKind: string
{
    case MatchFound = 'match_found';
    case Invite = 'invite';
    case InviteAccepted = 'invite_accepted';
    case Challenge = 'challenge';
    case GameStarted = 'game_started';
    case YourMove = 'your_move';
    case Reminder = 'reminder';
    case OpponentResigned = 'opponent_resigned';
    case GameOver = 'game_over';
    case ClanJoinRequest = 'clan_join_request';
    case ClanJoinAnswer = 'clan_join_answer';
    case InviteLinkTaken = 'invite_link_taken';
    case TournamentEntryRemoved = 'tournament_entry_removed';
    case TournamentNews = 'tournament_news';
    case CasualMatchFound = 'casual_match_found';
    case CasualInvite = 'casual_invite';
    case CasualLobbyShared = 'casual_lobby_shared';
    case CasualNoShow = 'casual_noshow';
    case CasualReport = 'casual_report';
    case CasualResult = 'casual_result';
    case CasualOpponentJoined = 'casual_opponent_joined';
    case CasualChallenge = 'casual_challenge';
    case CasualChallengeAnswer = 'casual_challenge_answer';
    case CasualReminder = 'casual_reminder';
    case CasualCheckIn = 'casual_checkin';
    case TournamentReminder = 'tournament_reminder';
    case BlockZero = 'block0';
    case SeasonPayout = 'season_payout';
    case OpponentRequest = 'opponent_request';

    /**
     * The page follows the link on its own after a short, cancellable
     * countdown: a live game is waiting and its clock will start.
     */
    public function redirects(): bool
    {
        return in_array($this, [self::MatchFound, self::InviteAccepted, self::CasualMatchFound], true);
    }

    /**
     * Where each kind may reach the player (audit of 2026-09-30, after "viel
     * zu viele" DMs). The bell and the page get every kind the player keeps
     * switched on; this decides only what leaves the site. The Notifier
     * enforces it, whatever the DM switch, the digest or a game's own choice
     * say (a stored per-kind value that asks for more is ignored, not deleted).
     *
     * A Nostr DM only for what a player has to act on while away, with hours
     * or days to do it: DMs arrive too slowly for anything live. Browser push
     * where seconds matter or where it is plain news; never for a live event
     * the player is already at. Both only while the player is not on the site
     * (OnSite), except the correspondence deadline reminder.
     *
     * | kind                     | fires when                                         | player is      | reach                 |
     * |--------------------------|----------------------------------------------------|----------------|-----------------------|
     * | match_found              | blitz queue paired (live, seconds)                 | in the queue   | page only             |
     * | invite                   | blitz invite from a friend (live, 120 s)           | on the site    | page only             |
     * | invite_accepted          | a blitz invite (link) was taken, board open        | anywhere       | push (seconds count)  |
     * | challenge                | correspondence / clan match challenge (48 h, days) | away           | push, DM              |
     * | game_started             | correspondence challenge accepted                  | away           | push; live: page only |
     * | your_move                | correspondence opponent moved (24 h a move)        | away           | push, never a DM (1)  |
     * | reminder                 | correspondence move due in 2/6/12 h                | away           | push, DM, even on site|
     * | opponent_resigned        | a game ended by resignation                        | away / at board| push if correspondence|
     * | game_over                | a game ended                                       | away / at board| push if correspondence|
     * | clan_join_request        | a player asks to join your clan (async)            | away           | push, DM              |
     * | clan_join_answer         | your join request was answered (news)              | away           | push                  |
     * | invite_link_taken        | your challenge link was taken (news)               | away           | push                  |
     * | tournament_entry_removed | an organizer removed your entry (news)             | away           | push                  |
     * | tournament_news          | paused, resumed, called off, organizer message     | away           | push, DM              |
     * | casual_match_found       | 1v1 queue paired, Ready within 60 s                | in the queue   | page only             |
     * | casual_invite            | 1v1 invite (live, 120 s)                           | on the site    | page only             |
     * | casual_lobby_shared      | host shared the lobby, join within 10 min          | match room     | push                  |
     * | casual_noshow            | no-show claimed, contest within 5 min              | away           | push (minutes)        |
     * | casual_report            | result reported, answer within 30 min              | just played    | push (minutes)        |
     * | casual_result            | a 1v1 ended, maybe decided by the league           | anywhere       | push                  |
     * | casual_opponent_joined   | guest joined the host's lobby                      | in the game    | page only             |
     * | casual_challenge         | scheduled 1v1 challenge (reply in days)            | away           | push, DM              |
     * | casual_challenge_answer  | your challenge accepted, declined, expired         | away           | push                  |
     * | casual_reminder          | scheduled 1v1 starts in 15 min, forfeit at stake   | away           | push, DM (2)          |
     * | casual_checkin           | check-in open, 10 min after the reminder           | away           | push                  |
     * | tournament_reminder      | league decides the match in 30 / 5 min             | away           | push, DM (2)          |
     * | block0                   | Block 0 date and release, asked for                | away           | push, DM              |
     * | season_payout            | season sats wait for a Lightning address (days)    | away           | push, DM              |
     * | opponent_request         | a player listed you as an opponent (accept)        | away           | push, DM              |
     *
     * (1) User decision 2026-09-30: "IMMER sinnlos". Push at most once per game
     * and hour, and not while the player is at the board (YourMoveThrottle).
     * (2) Minutes, not hours, but a game is forfeited: the one reminder that
     * has to reach a player who is not there. A decision to confirm.
     */
    public function dmAllowed(): bool
    {
        return in_array($this, [self::Challenge, self::Reminder, self::ClanJoinRequest, self::TournamentNews, self::CasualChallenge, self::CasualReminder, self::TournamentReminder, self::BlockZero, self::SeasonPayout, self::OpponentRequest], true);
    }

    /**
     * Browser push (see dmAllowed() for the table). A kind that covers live
     * and correspondence games decides by the game; without one it stays on
     * the page.
     */
    public function pushAllowed(ChessGame|BoardGame|null $game = null): bool
    {
        return match (true) {
            $this->pageOnly() => false,
            in_array($this, [self::GameStarted, self::YourMove, self::OpponentResigned, self::GameOver], true) => $game !== null && $game->isCorrespondence(),
            default => true,
        };
    }

    /**
     * Only on the page, never out: no switch on the settings page, and a
     * stored "off" is ignored (it would silence a live game's own call).
     */
    public function pageOnly(): bool
    {
        return $this->group() === null;
    }

    /**
     * Its group on the settings page: `correspondence`, `play` (live games
     * and 1v1), `community` (clans and tournaments) or `league`; null for a
     * page-only kind, which has no switch.
     *
     * @return 'correspondence'|'play'|'community'|'league'|null
     */
    public function group(): ?string
    {
        return match ($this) {
            self::MatchFound, self::Invite, self::CasualMatchFound, self::CasualInvite, self::CasualOpponentJoined => null,
            self::Challenge, self::GameStarted, self::YourMove, self::Reminder, self::OpponentResigned, self::GameOver => 'correspondence',
            self::InviteAccepted, self::CasualLobbyShared, self::CasualNoShow, self::CasualReport, self::CasualResult,
            self::CasualChallenge, self::CasualChallengeAnswer, self::CasualReminder, self::CasualCheckIn => 'play',
            self::ClanJoinRequest, self::ClanJoinAnswer, self::InviteLinkTaken, self::TournamentEntryRemoved, self::TournamentNews, self::TournamentReminder => 'community',
            self::BlockZero, self::SeasonPayout, self::OpponentRequest => 'league',
        };
    }

    /**
     * Goes out by push and DM even while the player is on the site: the
     * correspondence deadline reminder, where hours and the game are at
     * stake, and an open tab left behind on another device still counts as
     * on the site.
     */
    public function remoteWhileOnSite(): bool
    {
        return $this === self::Reminder;
    }

    /**
     * Toast tone (toast-stack): `challenge` asks for action, `success` is good
     * news, `confirmed` is information.
     */
    public function tone(): string
    {
        return match ($this) {
            self::MatchFound, self::Invite, self::InviteAccepted, self::Challenge, self::YourMove, self::Reminder, self::ClanJoinRequest, self::InviteLinkTaken,
            self::CasualMatchFound, self::CasualInvite, self::CasualLobbyShared, self::CasualNoShow, self::CasualReport,
            self::CasualChallenge, self::CasualReminder, self::CasualCheckIn, self::TournamentReminder, self::OpponentRequest => 'challenge',
            self::ClanJoinAnswer, self::TournamentEntryRemoved, self::TournamentNews, self::CasualResult, self::CasualOpponentJoined, self::CasualChallengeAnswer, self::BlockZero, self::SeasonPayout => 'confirmed',
            self::GameStarted, self::OpponentResigned => 'success',
            self::GameOver => 'confirmed',
        };
    }

    /**
     * Sound the page plays (resources/js/sounds.js). Game over picks its sound
     * from the outcome instead.
     */
    public function sound(): string
    {
        return match ($this) {
            self::MatchFound, self::InviteAccepted, self::CasualMatchFound => 'matchFound',
            self::GameStarted => 'gameStart',
            self::OpponentResigned => 'win',
            default => 'ping',
        };
    }

    /**
     * Label and hint of the switch on the chess settings page (English keys,
     * translated where they are shown).
     *
     * @return array{0: string, 1: string}
     */
    public function setting(): array
    {
        return match ($this) {
            self::MatchFound => ['Opponent found', 'the blitz queue paired you, the game starts'],
            self::Invite => ['Blitz invite', 'a friend invites you to a live game'],
            self::InviteAccepted => ['Invite accepted', 'your friend accepted, the game starts'],
            self::Challenge => ['Challenge received', 'someone challenged you to daily chess, a board game by correspondence, or your clan to a match'],
            self::GameStarted => ['Daily game started', 'your daily challenge was accepted'],
            self::YourMove => ['Your move', 'your opponent made their daily move'],
            self::Reminder => ['Deadline reminder', ':hours h before your daily move is due'],
            self::OpponentResigned => ['Opponent resigned', 'your opponent gave up the game'],
            self::GameOver => ['Game over', 'a game of yours ended'],
            self::ClanJoinRequest => ['Clan join request', 'a player asks to join your clan'],
            self::ClanJoinAnswer => ['Clan join answer', 'a clan answered your join request'],
            self::InviteLinkTaken => ['Invite link taken', 'someone took the invite link you shared'],
            self::TournamentEntryRemoved => ['Tournament entry removed', 'an organizer removed your entry from a tournament'],
            self::TournamentNews => ['Tournament news', 'a tournament you play in was paused, resumed or called off, or its organizer wrote to all players'],
            self::CasualMatchFound => ['1v1 opponent found', 'the casual 1v1 queue paired you, press Ready'],
            self::CasualInvite => ['1v1 invite', 'a player invites you to a casual 1v1'],
            self::CasualLobbyShared => ['1v1 lobby shared', 'your opponent shared the game lobby in the match chat'],
            self::CasualNoShow => ['1v1 no-show claimed', 'your opponent says you did not show up; contest it in time'],
            self::CasualReport => ['1v1 result to confirm', 'your opponent reported the result of your casual 1v1'],
            self::CasualResult => ['1v1 result', 'a casual 1v1 of yours ended'],
            self::CasualOpponentJoined => ['1v1 opponent joined', 'your opponent joined the lobby you shared'],
            self::CasualChallenge => ['1v1 challenge received', 'a player challenges you to a scheduled casual 1v1'],
            self::CasualChallengeAnswer => ['1v1 challenge answered', 'your 1v1 challenge was accepted, declined or expired'],
            self::CasualReminder => ['1v1 start reminder', 'a scheduled 1v1 of yours starts soon'],
            self::CasualCheckIn => ['1v1 check-in open', 'check in for your scheduled 1v1'],
            self::TournamentReminder => ['Tournament match reminder', 'your tournament match waits for you, and the league decides it on its own soon'],
            self::BlockZero => ['Block 0', 'you asked to be told: the date of Block 0, and when the board releases it'],
            self::SeasonPayout => ['Season payout', 'your season sats wait for a Lightning address in your Nostr profile'],
            self::OpponentRequest => ['Opponent request', 'a player added you as an opponent; accept or decline'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
