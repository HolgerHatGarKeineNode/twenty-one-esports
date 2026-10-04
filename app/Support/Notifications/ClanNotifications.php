<?php

namespace App\Support\Notifications;

use App\Enums\ClanRole;
use App\Enums\JoinRequestStatus;
use App\Enums\NotificationKind;
use App\Enums\Platform;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\User;
use Illuminate\Support\Traits\Localizable;

/**
 * Clan notifications (P5c, join requests since P6b): the captains hear about
 * a new request, the owner about a captain's yes (only the owner's key lists
 * players), and the player about the answer.
 *
 * Applications (plan "Clan-Bewerbungen"): the owner and every captain get the
 * bell only. Off the site the applicant's own signer sends them a NIP-17 DM
 * from the browser (applicationText()), so the league's key sends none on top.
 */
final class ClanNotifications
{
    use Localizable;

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
     * The owner and every captain (the owner is always one) hear about a new
     * application, in the bell only.
     */
    public function applied(ClanJoinRequest $request): void
    {
        $clan = $request->clan;
        $applicant = $request->user;
        $captains = $clan->members()->where('role', ClanRole::Captain)->with('user')->get();

        foreach ($captains as $member) {
            $captain = $member->user;
            $locale = $captain->locale ?? (string) config('app.locale');

            $this->notifier->send($captain, NotificationKind::ClanJoinRequest, new Notice(
                __(':name applied to :clan', ['name' => $applicant->displayName(), 'clan' => $clan->name], $locale),
                __('Accept or decline on the clan page.', [], $locale),
                route('clans.manage', $clan).'#join-requests',
                null,
                __('Answer', [], $locale),
            ), remote: false, sender: $applicant);
        }
    }

    /**
     * The applicant's NIP-17 text to one captain, in the captain's language:
     * who applies, the form, and the way to answer.
     */
    public function applicationText(ClanJoinRequest $request, User $captain): string
    {
        return $this->withLocale($captain->locale ?? (string) config('app.locale'), function () use ($request): string {
            $clan = $request->clan;
            $registry = app(GameRegistry::class);
            $lines = [
                __('Application to :clan [:tag] from :name', ['clan' => $clan->name, 'tag' => $clan->clantag, 'name' => $request->user->displayName()]),
                '',
                __('Games: :games', ['games' => implode(', ', array_map($registry->name(...), $request->games ?? []))]),
            ];

            if (($request->platforms ?? []) !== []) {
                $lines[] = __('Platforms: :platforms', ['platforms' => implode(', ', array_map(fn (string $platform): string => Platform::tryFrom($platform)?->label() ?? $platform, $request->platforms ?? []))]);
            }

            $lines[] = __('Time zone: :zone', ['zone' => $request->timezone ?? '–']);

            if (filled($request->message)) {
                $lines[] = '';
                $lines[] = '"'.$request->message.'"';
            }

            $lines[] = '';
            $lines[] = __('Accept or decline: :url', ['url' => route('clans.manage', $clan).'#join-requests']);

            return implode("\n", $lines);
        });
    }

    /**
     * A captain's reply to a declined application, in the applicant's
     * language, for the captain's signer to send as NIP-17.
     */
    public function declineText(ClanJoinRequest $request, string $reply): string
    {
        $locale = $request->user->locale ?? (string) config('app.locale');

        return __(':clan declined your application and replies:', ['clan' => $request->clan->name], $locale)."\n\n".$reply."\n\n".route('clans.show', $request->clan);
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
