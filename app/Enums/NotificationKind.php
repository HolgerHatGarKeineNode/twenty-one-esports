<?php

namespace App\Enums;

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;

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
    case CupGameNow = 'cup_game_now';
    case LeagueWeekApproval = 'league_week_approval';
    case LeagueAlert = 'league_alert';
    case TeamMatch = 'team_match';
    case NewTournament = 'new_tournament';

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
     * or days to do it: DMs arrive too slowly for anything live or
     * minute-scale ("live → no DM", user 2026-09-30). Browser push where
     * minutes matter or where it is plain news; never for a live event the
     * player is already at. Neither is sent while the player is on the site
     * (OnSite; the bell and the toast reach them there), except the
     * correspondence deadline reminder.
     *
     * Every sender, per kind (grep `NotificationKind::` to check the list):
     *
     * | kind                     | senders and what they say                                                   | time to act    | reach            |
     * |--------------------------|-----------------------------------------------------------------------------|----------------|------------------|
     * | match_found              | ChessNotifications::matchFound (blitz queue paired)                         | seconds        | page only        |
     * | invite                   | ChessNotifications::inviteReceived (blitz invite from a friend)             | 120 s          | page only        |
     * | invite_accepted          | ChessNotifications::inviteAccepted (remote: false); InviteLinks::announce   | now            | push (link only) |
     * |                          | (a blitz invite link was taken, the board is open)                          |                |                  |
     * | challenge                | Chess/BoardNotifications::challengeReceived; SeriesService (clan match)     | 48 h, days     | push, DM         |
     * | game_started             | Chess/BoardNotifications::gameStarted (correspondence challenge accepted,   | 24 h           | push             |
     * |                          | also via InviteLinks for a daily link)                                      |                |                  |
     * | your_move                | Chess/BoardNotifications::yourMove (correspondence only)                    | 24 h           | push (1)         |
     * | reminder                 | Chess/BoardNotifications::reminder (`*:daily-reminders`, 2/6/12 h left)     | hours          | push, DM (2)     |
     * | opponent_resigned        | Chess/BoardNotifications::gameOver                                          | none (news)    | push if corresp. |
     * | game_over                | Chess/BoardNotifications::gameOver                                          | none (news)    | push if corresp. |
     * | clan_join_request        | ClanNotifications::joinRequested (captains), ::joinApproved (the owner)     | days           | push, DM         |
     * | clan_join_answer         | ClanNotifications::joinAnswered (listed: confirm to join, or declined)      | none (news)    | push             |
     * | invite_link_taken        | InviteLinks::announce (a clan took your challenge link)                     | none (news)    | push             |
     * | tournament_entry_removed | TournamentControl, TournamentModeration (entry removed)                     | none (news)    | push             |
     * | tournament_news          | TournamentControl (paused, goes on, called off, organizer message);         | hours, days    | push, DM         |
     * |                          | CasualCupNotices (called off, live evening set, moved, match open with a    |                |                  |
     * |                          | 36-48 h window, times suggested, time agreed)                               |                |                  |
     * | cup_game_now             | CasualCupNotices::invited (play-now invite, 600 s), ::gameStarted (the      | minutes        | push             |
     * |                          | league started a cup game: 600 s to the first move); TournamentReminders    |                |                  |
     * |                          | (2 min into the first-move window: the opponent is waiting)                 |                |                  |
     * | casual_match_found       | CasualNotifications::matchFound (Ready within 60 s)                         | seconds        | page only        |
     * | casual_invite            | CasualNotifications::inviteReceived (120 s)                                 | 120 s          | page only        |
     * | casual_lobby_shared      | CasualNotifications::lobbyShared (join within 10 min)                       | 10 min         | push             |
     * | casual_noshow            | CasualNotifications::noShowClaimed (contest within 5 min)                   | 5 min          | push             |
     * | casual_report            | CasualNotifications::reportToConfirm (answer within 30 min)                 | 30 min         | push             |
     * | casual_result            | CasualNotifications::result                                                 | none (news)    | push             |
     * | casual_opponent_joined   | CasualNotifications::opponentJoined (the host is in the game)               | seconds        | page only        |
     * | casual_challenge         | CasualNotifications::challengeReceived (reply in days)                      | days           | push, DM         |
     * | casual_challenge_answer  | CasualNotifications::challengeAnswered                                      | none (news)    | push             |
     * | casual_reminder          | CasualNotifications::reminder (15 min before the start)                     | 15 min         | push             |
     * | casual_checkin           | CasualNotifications::checkInOpen (10 min before to 10 min after)            | 20 min         | push             |
     * | tournament_reminder      | TournamentReminders (30 and 5 min before the league decides; by hand)       | minutes        | push             |
     * | block0                   | BlockZeroNotifications (asked for: the date, the release)                   | days           | push, DM         |
     * | season_payout            | SeasonSettlement (sats wait for a Lightning address)                        | days           | push, DM         |
     * | opponent_request         | OpponentRequests (accept or decline)                                        | days           | push, DM         |
     * | league_week_approval     | LeagueWeekDrafts (admins only: a league week needs approving, a reminder,   | days           | page only        |
     * |                          | the TMNF server could not be switched to the week's track)                  |                |                  |
     * | league_alert             | TournamentDraws (admins only: a tournament's close or draw failed; once     | hours          | page only        |
     * |                          | per tournament and hour); TournamentPreflight (a check before the close     |                |                  |
     * |                          | or draw failed; once per tournament, check and hour)                        |                |                  |
     * | team_match               | TeamMatchNotifications (chess team match: lineup lock with the board order, | minutes        | push             |
     * |                          | lock missed, a board started, the result)                                   |                |                  |
     * | new_tournament           | NotifyTournamentWatchers (asked for: a new tournament of a game opened for  | days           | push, DM         |
     * |                          | sign-up; once per tournament, never a casual cup)                           |                |                  |
     *
     * (1) Never a DM (user decision 2026-09-30: "IMMER sinnlos"). A push at
     * most once per game and hour, and not while the player is at the board
     * (YourMoveThrottle).
     * (2) Also while the player is on the site (remoteWhileOnSite()).
     */
    public function dmAllowed(): bool
    {
        return in_array($this, [self::Challenge, self::Reminder, self::ClanJoinRequest, self::TournamentNews, self::CasualChallenge, self::BlockZero, self::SeasonPayout, self::OpponentRequest, self::NewTournament], true);
    }

    /**
     * Browser push (see dmAllowed() for the table). A kind that covers live
     * and correspondence games decides by the game; without one it stays on
     * the page.
     */
    public function pushAllowed(ChessGame|BoardGame|HyperMatch|null $game = null): bool
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
            // The admins' league week approvals: no switch, an admin cannot opt out of the week they must approve.
            self::MatchFound, self::Invite, self::CasualMatchFound, self::CasualInvite, self::CasualOpponentJoined, self::LeagueWeekApproval, self::LeagueAlert => null,
            self::Challenge, self::GameStarted, self::YourMove, self::Reminder, self::OpponentResigned, self::GameOver => 'correspondence',
            self::InviteAccepted, self::CasualLobbyShared, self::CasualNoShow, self::CasualReport, self::CasualResult,
            self::CasualChallenge, self::CasualChallengeAnswer, self::CasualReminder, self::CasualCheckIn => 'play',
            self::ClanJoinRequest, self::ClanJoinAnswer, self::InviteLinkTaken, self::TournamentEntryRemoved, self::TournamentNews, self::CupGameNow, self::TournamentReminder, self::TeamMatch, self::NewTournament => 'community',
            self::BlockZero, self::SeasonPayout, self::OpponentRequest => 'league',
        };
    }

    /**
     * How far it reaches, as the settings page says it (an English key; the
     * page adds that nothing is sent while the player is on the site).
     */
    public function reach(): string
    {
        return match (true) {
            $this->pageOnly() => 'only on the page',
            $this === self::Reminder => 'bell, push and DM, even while you are here',
            $this->dmAllowed() => 'bell, push and DM',
            $this === self::YourMove => 'push at most once an hour per game; the game bar shows your turn',
            in_array($this, [self::OpponentResigned, self::GameOver], true) => 'bell; push only for correspondence games',
            $this === self::InviteAccepted => 'bell; push only for an invite link',
            default => 'bell and push',
        };
    }

    /**
     * Stored in the bell. "Your move" is not: one entry per move of every
     * blitz and correspondence game buried what matters (a match room, a
     * tournament on the day), and the floating game bar already shows whose
     * turn it is. It still goes out by push, throttled (YourMoveThrottle).
     */
    public function inBell(): bool
    {
        return $this !== self::YourMove;
    }

    /**
     * Goes out by push and DM even while the player is on the site: the
     * correspondence deadline reminder. Hours and the game are at stake, and
     * "on the site" only means a visible page, which may have nobody at it
     * (a tab left open on a desktop at home).
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
            self::CasualChallenge, self::CasualReminder, self::CasualCheckIn, self::TournamentReminder, self::OpponentRequest, self::CupGameNow, self::LeagueWeekApproval, self::LeagueAlert, self::TeamMatch => 'challenge',
            self::ClanJoinAnswer, self::TournamentEntryRemoved, self::TournamentNews, self::CasualResult, self::CasualOpponentJoined, self::CasualChallengeAnswer, self::BlockZero, self::SeasonPayout, self::NewTournament => 'confirmed',
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
            self::MatchFound => ['Opponent found', 'the live queue paired you, the game starts'],
            self::Invite => ['Live invite', 'a friend invites you to a live game'],
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
            self::TournamentNews => ['Tournament news', 'a tournament you play in opened your match, set or moved a time, was paused, resumed or called off, or its organizer wrote to all players'],
            self::CupGameNow => ['Cup game now', 'your cup opponent wants to play now, or the league started your cup game'],
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
            self::LeagueWeekApproval => ['League week approval', 'admins only: a Blockfill or TMNF week waits for your approval'],
            self::LeagueAlert => ['League alert', 'admins only: a tournament could not be closed or drawn'],
            self::NewTournament => ['New tournament', 'you asked to be told: a new tournament of a game you follow opened for sign-up'],
            self::TeamMatch => ['Clan team match', 'your clan team match locked its lineups with your board, missed the lock, started your board or ended'],
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
