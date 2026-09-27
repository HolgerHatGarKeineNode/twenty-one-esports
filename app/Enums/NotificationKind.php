<?php

namespace App\Enums;

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

    /**
     * The page follows the link on its own after a short, cancellable
     * countdown: a live game is waiting and its clock will start.
     */
    public function redirects(): bool
    {
        return in_array($this, [self::MatchFound, self::InviteAccepted, self::CasualMatchFound], true);
    }

    /**
     * Goes out by Nostr DM for a player who never chose the DM channel
     * (ChessSettings::$dm null): the events an offline player has to act
     * on. A player who switched DMs on gets every kind that goes out
     * remotely, one who switched them off gets none.
     */
    public function dmByDefault(): bool
    {
        return in_array($this, [self::Challenge, self::YourMove, self::Reminder, self::ClanJoinRequest, self::TournamentNews, self::CasualNoShow, self::CasualReport], true);
    }

    /**
     * Toast tone (toast-stack): `challenge` asks for action, `success` is good
     * news, `confirmed` is information.
     */
    public function tone(): string
    {
        return match ($this) {
            self::MatchFound, self::Invite, self::InviteAccepted, self::Challenge, self::YourMove, self::Reminder, self::ClanJoinRequest, self::InviteLinkTaken,
            self::CasualMatchFound, self::CasualInvite, self::CasualLobbyShared, self::CasualNoShow, self::CasualReport => 'challenge',
            self::ClanJoinAnswer, self::TournamentEntryRemoved, self::TournamentNews, self::CasualResult => 'confirmed',
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
            self::Challenge => ['Challenge received', 'someone challenged you to daily chess or your clan to a match'],
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
