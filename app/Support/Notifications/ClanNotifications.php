<?php

namespace App\Support\Notifications;

use App\Enums\ClanRole;
use App\Enums\NotificationKind;
use App\Models\Clan;
use App\Models\User;

/**
 * Clan notifications (P5c). Only the hook exists yet: join requests arrive
 * with P4b, which calls joinRequested() once a request is stored.
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
                route('clans.manage', $clan),
                null,
                __('Answer', [], $locale),
            ));
        }
    }
}
