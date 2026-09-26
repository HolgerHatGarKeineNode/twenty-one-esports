<?php

namespace App\Support\Notifications;

use App\Enums\ClanRole;
use App\Enums\JoinRequestStatus;
use App\Enums\NotificationKind;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\User;

/**
 * Clan notifications (P5c, join requests since P6b): the captains hear about
 * a new request, the owner about a captain's yes (only the owner's key lists
 * players), and the player about the answer.
 */
final class ClanNotifications
{
    public function __construct(private Notifier $notifier) {}

    /**
     * Every captain of the clan hears about a new join request.
     */
    public function joinRequested(Clan $clan, User $applicant): void
    {
        $captains = $clan->members()->where('role', ClanRole::Captain)->with('user')->get();

        foreach ($captains as $member) {
            $captain = $member->user;
            $locale = $captain->locale ?? (string) config('app.locale');

            $this->notifier->send($captain, NotificationKind::ClanJoinRequest, new Notice(
                __(':name wants to join :clan', ['name' => $applicant->displayName(), 'clan' => $clan->name], $locale),
                __('Accept or decline on the clan page.', [], $locale),
                route('clans.manage', $clan).'#join-requests',
                null,
                __('Answer', [], $locale),
            ), sender: $applicant);
        }
    }

    /**
     * A captain approved; the owner's key has to list the player now.
     */
    public function joinApproved(ClanJoinRequest $request): void
    {
        $owner = $request->clan->owner;

        if ($owner === null) {
            return;
        }

        $locale = $owner->locale ?? (string) config('app.locale');

        $this->notifier->send($owner, NotificationKind::ClanJoinRequest, new Notice(
            __(':captain approved :name for :clan', ['captain' => $request->decidedBy?->displayName() ?? '', 'name' => $request->user->displayName(), 'clan' => $request->clan->name], $locale),
            __('Confirm with your key to add them to the clan record.', [], $locale),
            route('clans.manage', $request->clan).'#join-requests',
            null,
            __('Confirm', [], $locale),
        ), sender: $request->user);
    }

    /**
     * The player hears the answer: listed (confirm to join) or declined.
     */
    public function joinAnswered(ClanJoinRequest $request): void
    {
        $player = $request->user;
        $locale = $player->locale ?? (string) config('app.locale');
        $clan = $request->clan;

        $notice = $request->status === JoinRequestStatus::Listed && $request->clanInvite !== null
            ? new Notice(
                __(':clan said yes', ['clan' => $clan->name], $locale),
                __('Confirm your membership to join the roster.', [], $locale),
                route('invites.show', $request->clanInvite),
                null,
                __('Join', [], $locale),
            )
            : new Notice(
                __(':clan declined your request', ['clan' => $clan->name], $locale),
                __('You can keep playing casual games, or ask another clan.', [], $locale),
                route('clans.index'),
            );

        $this->notifier->send($player, NotificationKind::ClanJoinAnswer, $notice);
    }
}
