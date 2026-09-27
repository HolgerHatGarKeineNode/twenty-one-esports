<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SeriesResolution;
use App\Games\GameRegistry;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The casual 1v1 notifications (P23), each written in the recipient's
 * language and handed to the Notifier (bell, web push, NIP-17 DM as the
 * player chose; NotificationKind lists them):
 *
 * - casual_match_found: the queue or an invite paired you, press Ready
 * - casual_invite: a player invites you
 * - casual_lobby_shared: the host shared the lobby, join it
 * - casual_noshow: the opponent claims you did not show, contest in time
 * - casual_report: the opponent reported the result, confirm or dispute
 * - casual_result: the match ended (confirmed, decided, forfeit, void)
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
