<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SeriesResolution;
use App\Games\GameRegistry;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The casual 1v1 notifications (P23), each written in the recipient's
 * language and handed to the Notifier (bell, web push, NIP-17 DM as the
 * player chose; NotificationKind lists them):
 *
 * - casual_match_found: the queue or an invite paired you, press Ready
 * - casual_invite: a player invites you
 * - casual_lobby_shared: the host shared the lobby, join it
 * - casual_opponent_joined: the guest joined the lobby the host shared
 * - casual_noshow: the opponent claims you did not show, contest in time
 * - casual_report: the opponent reported the result, confirm or dispute
 * - casual_result: the match ended (confirmed, decided, forfeit, void)
 * - casual_challenge: a scheduled 1v1 challenge came in (P23 S4)
 * - casual_challenge_answer: it was accepted, declined or expired
 * - casual_reminder / casual_checkin: before the agreed start
 *
 * Never lobby data (NIP "Notifications"): the lobby itself only ever
 * travels in the end-to-end encrypted match chat.
 */
final class CasualNotifications
{
    public function __construct(private Notifier $notifier, private GameRegistry $games) {}

    public function matchFound(SeriesMatch $match): void
    {
        foreach (SeriesMatch::SIDES as $side) {
            foreach ($this->players($match, $side) as $player) {
                $locale = $this->locale($player);

                $this->notifier->send($player, NotificationKind::CasualMatchFound, new Notice(
                    __('1v1 opponent found: :name', ['name' => $match->sideName(SeriesMatch::otherSide($side))], $locale),
                    __(':game 1v1 · match :number. Press Ready within :seconds seconds.', [
                        'game' => $this->games->name($match->game),
                        'number' => $match->label(),
                        'seconds' => $match->casualSetting('ready_seconds'),
                    ], $locale),
                    route('matches.room', $match),
                    $match->number,
                    __('Ready', [], $locale),
                ));
            }
        }
    }

    public function inviteReceived(SeriesInvite $invite): void
    {
        $player = $invite->invitee;
        $locale = $this->locale($player);

        $this->notifier->send($player, NotificationKind::CasualInvite, new Notice(
            __(':name invites you to a 1v1', ['name' => $invite->inviter->displayName()], $locale),
            __(':game 1v1 · Casual · host drawn at random', ['game' => $this->games->name($invite->game)], $locale),
            route('games.series', ['slug' => $invite->game]),
            null,
            __('Answer', [], $locale),
        ), sender: $invite->inviter);
    }

    public function lobbyShared(SeriesMatch $match): void
    {
        $host = $match->host_side === 'challenged' ? 'challenged' : 'challenger';

        foreach ($this->players($match, SeriesMatch::otherSide($host)) as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CasualLobbyShared, new Notice(
                __(':name shared the lobby', ['name' => $match->sideName($host)], $locale),
                __('Match :number: open the match chat and join within :minutes minutes.', [
                    'number' => $match->label(),
                    'minutes' => $match->casualSetting('join_minutes'),
                ], $locale),
                route('matches.room', $match),
                $match->number,
                __('Join', [], $locale),
            ));
        }
    }

    public function opponentJoined(SeriesMatch $match): void
    {
        $host = $match->host_side === 'challenged' ? 'challenged' : 'challenger';

        foreach ($this->players($match, $host) as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CasualOpponentJoined, new Notice(
                __(':name joined your lobby', ['name' => $match->sideName(SeriesMatch::otherSide($host))], $locale),
                __('Match :number: start the game.', ['number' => $match->label()], $locale),
                route('matches.room', $match),
                $match->number,
            ));
        }
    }

    public function noShowClaimed(SeriesMatch $match): void
    {
        $claimer = (string) $match->noshow_side;

        foreach ($this->players($match, SeriesMatch::otherSide($claimer)) as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CasualNoShow, new Notice(
                __(':name says you did not show up', ['name' => $match->sideName($claimer)], $locale),
                __('Match :number: contest it within :minutes minutes, or you lose by forfeit.', [
                    'number' => $match->label(),
                    'minutes' => $match->casualSetting('contest_minutes'),
                ], $locale),
                route('matches.room', $match),
                $match->number,
                __('Contest', [], $locale),
            ));
        }
    }

    public function reportToConfirm(SeriesMatch $match): void
    {
        $reported = $match->latestReport?->side;

        if ($reported === null) {
            return;
        }

        foreach ($this->players($match, SeriesMatch::otherSide($reported)) as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CasualReport, new Notice(
                __(':name reported the result', ['name' => $match->sideName($reported)], $locale),
                __('Match :number: confirm or dispute it within :minutes minutes, or it counts as reported.', [
                    'number' => $match->label(),
                    'minutes' => $match->casualSetting('confirm_minutes'),
                ], $locale),
                route('matches.room', $match),
                $match->number,
                __('Answer', [], $locale),
            ));
        }
    }

    public function result(SeriesMatch $match): void
    {
        foreach (SeriesMatch::SIDES as $side) {
            foreach ($this->players($match, $side) as $player) {
                $locale = $this->locale($player);
                $other = $match->sideName(SeriesMatch::otherSide($side));

                $body = match (true) {
                    $match->resolution === SeriesResolution::Void => __('Match :number against :name is void: no result counts.', ['number' => $match->label(), 'name' => $other], $locale),
                    $match->winner === $side => __('You won match :number against :name.', ['number' => $match->label(), 'name' => $other], $locale),
                    default => __('You lost match :number against :name.', ['number' => $match->label(), 'name' => $other], $locale),
                };

                $this->notifier->send($player, NotificationKind::CasualResult, new Notice(
                    __(':game 1v1 result', ['game' => $this->games->name($match->game)], $locale),
                    $match->resolution === SeriesResolution::Forfeit ? $body.' '.__('(no-show forfeit)', [], $locale) : $body,
                    route('matches.show', $match),
                    $match->number,
                ));
            }
        }
    }

    public function challengeReceived(SeriesMatch $match): void
    {
        foreach ($this->players($match, 'challenged') as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::CasualChallenge, new Notice(
                __(':name challenges you to a 1v1', ['name' => $match->challenger_name], $locale),
                __(':game 1v1 · match :number. Pick one of :count times by :time.', [
                    'game' => $this->games->name($match->game),
                    'number' => $match->label(),
                    'count' => count($match->proposals),
                    'time' => $this->time($match->respond_by, $player),
                ], $locale),
                route('matches.room', $match),
                $match->number,
                __('Answer', [], $locale),
            ), sender: $match->createdBy);
        }
    }

    /**
     * @param  'accepted'|'declined'|'expired'  $answer
     */
    public function challengeAnswered(SeriesMatch $match, string $answer): void
    {
        foreach ($this->players($match, 'challenger') as $player) {
            $locale = $this->locale($player);
            $name = $match->challenged_name;

            $this->notifier->send($player, NotificationKind::CasualChallengeAnswer, new Notice(
                match ($answer) {
                    'accepted' => __(':name accepted your 1v1 challenge', ['name' => $name], $locale),
                    'declined' => __(':name declined your 1v1 challenge', ['name' => $name], $locale),
                    default => __('Your 1v1 challenge to :name expired', ['name' => $name], $locale),
                },
                $answer === 'accepted' && $match->start_at !== null
                    ? __('Match :number starts :time. Check in from :minutes minutes before.', ['number' => $match->label(), 'time' => $this->time($match->start_at, $player), 'minutes' => $match->casualSetting('checkin_before_minutes')], $locale)
                    : __('Match :number will not be played.', ['number' => $match->label()], $locale),
                route('matches.room', $match),
                $match->number,
            ));
        }
    }

    public function reminder(SeriesMatch $match): void
    {
        foreach (SeriesMatch::SIDES as $side) {
            foreach ($this->players($match, $side) as $player) {
                $locale = $this->locale($player);

                $this->notifier->send($player, NotificationKind::CasualReminder, new Notice(
                    __('Your 1v1 against :name starts :time', ['name' => $match->sideName(SeriesMatch::otherSide($side)), 'time' => $this->time($match->start_at ?? now(), $player)], $locale),
                    __('Match :number: check in from :minutes minutes before the start, or you lose by forfeit.', ['number' => $match->label(), 'minutes' => $match->casualSetting('checkin_before_minutes')], $locale),
                    route('matches.room', $match),
                    $match->number,
                ));
            }
        }
    }

    public function checkInOpen(SeriesMatch $match): void
    {
        foreach (SeriesMatch::SIDES as $side) {
            foreach ($this->players($match, $side) as $player) {
                $locale = $this->locale($player);

                $this->notifier->send($player, NotificationKind::CasualCheckIn, new Notice(
                    __('Check in for your 1v1 against :name', ['name' => $match->sideName(SeriesMatch::otherSide($side))], $locale),
                    __('Match :number: check in by :time, or you lose by forfeit.', ['number' => $match->label(), 'time' => $this->time($match->ready_by ?? now(), $player)], $locale),
                    route('matches.room', $match),
                    $match->number,
                    __('Check in', [], $locale),
                ));
            }
        }
    }

    private function time(CarbonInterface $at, User $player): string
    {
        return $at->copy()->timezone($player->timezone ?? config('esports.preseason.display_timezone'))->format('D H:i');
    }

    /**
     * @return Collection<int, User>
     */
    private function players(SeriesMatch $match, string $side): Collection
    {
        return User::query()->whereIn('id', $match->rosterSide($side))->get();
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }
}
