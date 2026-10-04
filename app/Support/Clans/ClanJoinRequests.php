<?php

namespace App\Support\Clans;

use App\Enums\ClanRole;
use App\Enums\JoinRequestOrigin;
use App\Enums\JoinRequestStatus;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Notifications\ClanNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Join requests through a clan link (P6b), NIP decision (a): the request and
 * the captain's yes are league data; only the owner's key lists a player in
 * the clan event (kind 32150). So there are two steps after the request:
 *
 *   1. a captain approves (or declines). An owner who approves lists the
 *      player in the same step, signing the clan event;
 *   2. the owner lists an approved player: that is the named clan invite of
 *      ClanService (prepareInvite / invite), unchanged. The player then joins
 *      with their own membership (12150) on /invites/{ulid}, as before.
 *
 * The requester sees each step honestly (pending, approved, listed).
 *
 * Applications (plan "Clan-Bewerbungen", P2): a player applies on the clan
 * page without a link, with a short form (ClanApplication). The same request,
 * origin `application`, the same two steps. Only while the clan's
 * "Applications open" switch is on, one open application per clan and
 * MAX_OPEN_APPLICATIONS open per player. The captains hear about it in the
 * bell; off the site the applicant's own signer sends them a NIP-17 DM from
 * the browser (applicationDms()), so the league's key sends none on top.
 */
final class ClanJoinRequests
{
    public const MAX_OPEN_APPLICATIONS = 3;

    public function __construct(private ClanService $clans, private ClanNotifications $notifications) {}

    /**
     * @throws ClanRuleViolation
     */
    public function request(Clan $clan, User $player, ?InviteLink $link = null): ClanJoinRequest
    {
        if (ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $player->id])->exists()) {
            throw new ClanRuleViolation(__('You are already in :clan.', ['clan' => $clan->name]));
        }

        if ($this->openFor($clan, $player) !== null) {
            throw new ClanRuleViolation(__('You already asked to join :clan.', ['clan' => $clan->name]));
        }

        $request = ClanJoinRequest::query()->create([
            'clan_id' => $clan->id,
            'user_id' => $player->id,
            'invite_link_id' => $link?->id,
            'status' => JoinRequestStatus::Pending,
        ]);

        $this->notifications->joinRequested($clan, $player);

        return $request;
    }

    /**
     * @throws ClanRuleViolation
     */
    public function apply(Clan $clan, User $player, ClanApplication $form): ClanJoinRequest
    {
        if (ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $player->id])->exists()) {
            throw new ClanRuleViolation(__('You are already in :clan.', ['clan' => $clan->name]));
        }

        if (! $clan->applications_open) {
            throw new ClanRuleViolation(__(':clan is not taking applications right now.', ['clan' => $clan->name]));
        }

        return DB::transaction(function () use ($clan, $player, $form): ClanJoinRequest {
            // Serialises two submits of the same player (SQLite locks the file, MySQL the user row).
            User::query()->whereKey($player->id)->lockForUpdate()->first();

            if ($this->openFor($clan, $player) !== null) {
                throw new ClanRuleViolation(__('You already asked to join :clan.', ['clan' => $clan->name]));
            }

            $open = ClanJoinRequest::query()
                ->where(['user_id' => $player->id, 'origin' => JoinRequestOrigin::Application])
                ->whereIn('status', [JoinRequestStatus::Pending, JoinRequestStatus::Approved])
                ->count();

            if ($open >= self::MAX_OPEN_APPLICATIONS) {
                throw new ClanRuleViolation(__('You have :count open applications. Wait for an answer or withdraw one first.', ['count' => $open]));
            }

            $request = ClanJoinRequest::query()->create([
                'clan_id' => $clan->id,
                'user_id' => $player->id,
                'origin' => JoinRequestOrigin::Application,
                'status' => JoinRequestStatus::Pending,
                'games' => $form->games,
                'platforms' => $form->platforms,
                'timezone' => $form->timezone,
                'message' => $form->message,
            ]);

            $this->notifications->applied($request);

            return $request;
        });
    }

    /**
     * The clan's "Applications open" switch; a captain flips it.
     *
     * @throws ClanRuleViolation
     */
    public function setApplicationsOpen(Clan $clan, User $captain, bool $open): void
    {
        $this->assertCaptain($clan, $captain);

        $clan->update(['applications_open' => $open]);
    }

    /**
     * The NIP-17 texts the applicant's signer sends right after applying: one
     * per captain (the owner is one) with a key, each in that captain's
     * language, with the way to answer.
     *
     * @return list<array{pubkey: string, content: string}>
     */
    public function applicationDms(ClanJoinRequest $request): array
    {
        $request = $this->fresh($request);

        $dms = [];

        foreach ($request->clan->members()->where('role', ClanRole::Captain)->with('user')->get() as $member) {
            $captain = $member->user;

            if (filled($captain->pubkey) && $captain->pubkey !== $request->user->pubkey) {
                $dms[] = ['pubkey' => (string) $captain->pubkey, 'content' => $this->notifications->applicationText($request, $captain)];
            }
        }

        return $dms;
    }

    /**
     * A captain's optional reply to a declined application, for the
     * captain's signer to send the applicant as NIP-17. Null without a reply
     * or without a key to send it to.
     *
     * @return array{pubkey: string, content: string}|null
     */
    public function declineDm(ClanJoinRequest $request, string $reply): ?array
    {
        $reply = trim($reply);
        $request = $this->fresh($request);

        if ($reply === '' || blank($request->user->pubkey)) {
            return null;
        }

        return ['pubkey' => (string) $request->user->pubkey, 'content' => $this->notifications->declineText($request, $reply)];
    }

    /**
     * The player's latest application or request to this clan, open or
     * answered, for the status on the clan page. A withdrawn one is no
     * longer shown.
     */
    public function latestFor(Clan $clan, User $player): ?ClanJoinRequest
    {
        return ClanJoinRequest::query()
            ->where(['clan_id' => $clan->id, 'user_id' => $player->id])
            ->with('clanInvite')
            ->latest('id')
            ->first();
    }

    /**
     * A captain says yes. Only the owner can list the player (next step), so
     * a captain who is not the owner hands the request on to them.
     *
     * @throws ClanRuleViolation
     */
    public function approve(ClanJoinRequest $request, User $captain): void
    {
        $request = $this->fresh($request);
        $this->assertCaptain($request->clan, $captain);

        if ($request->status !== JoinRequestStatus::Pending) {
            throw new ClanRuleViolation(__('This request is no longer open.'));
        }

        $request->update(['status' => JoinRequestStatus::Approved, 'decided_by_id' => $captain->id, 'decided_at' => now()]);

        if (! $request->clan->isOwner($captain)) {
            $this->notifications->joinApproved($request->refresh());
        }
    }

    /**
     * @throws ClanRuleViolation
     */
    public function decline(ClanJoinRequest $request, User $captain): void
    {
        $request = $this->fresh($request);
        $this->assertCaptain($request->clan, $captain);

        if (! $request->status->isOpen()) {
            throw new ClanRuleViolation(__('This request is no longer open.'));
        }

        $request->update(['status' => JoinRequestStatus::Declined, 'decided_by_id' => $captain->id, 'decided_at' => now()]);
        $this->notifications->joinAnswered($request->refresh());
    }

    /**
     * @throws ClanRuleViolation
     */
    public function withdraw(ClanJoinRequest $request, User $player): void
    {
        $request = $this->fresh($request);

        if ($request->user_id !== $player->id || ! $request->status->isOpen()) {
            throw new ClanRuleViolation(__('This request is no longer open.'));
        }

        $request->update(['status' => JoinRequestStatus::Withdrawn]);
    }

    /**
     * The clan event the owner signs to list the player (ClanService's named
     * invite). Pending or approved: the owner's listing is their own yes.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ClanRuleViolation
     */
    public function prepareList(ClanJoinRequest $request, User $owner): array
    {
        $request = $this->fresh($request);
        $this->assertListable($request);

        return $this->clans->prepareInvite($owner, $request->clan, $request->user);
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws ClanRuleViolation|RejectedEvent
     */
    public function list(ClanJoinRequest $request, User $owner, array $signed): ClanInvite
    {
        $request = $this->fresh($request);
        $this->assertListable($request);

        $invite = DB::transaction(function () use ($request, $owner, $signed): ClanInvite {
            $invite = $this->clans->invite($owner, $request->clan, $request->user, $signed);

            $request->update([
                'status' => JoinRequestStatus::Listed,
                'clan_invite_id' => $invite->id,
                'decided_by_id' => $request->decided_by_id ?? $owner->id,
                'decided_at' => $request->decided_at ?? now(),
            ]);

            return $invite;
        });

        $this->notifications->joinAnswered($request->refresh());

        return $invite;
    }

    /**
     * The player's request to this clan that still waits for the clan.
     */
    public function openFor(Clan $clan, User $player): ?ClanJoinRequest
    {
        return ClanJoinRequest::query()
            ->where(['clan_id' => $clan->id, 'user_id' => $player->id])
            ->whereIn('status', [JoinRequestStatus::Pending, JoinRequestStatus::Approved])
            ->latest('id')
            ->first();
    }

    /**
     * Requests the clan still has to act on, oldest first.
     *
     * @return Collection<int, ClanJoinRequest>
     */
    public function openRequests(Clan $clan): Collection
    {
        return ClanJoinRequest::query()
            ->where('clan_id', $clan->id)
            ->whereIn('status', [JoinRequestStatus::Pending, JoinRequestStatus::Approved])
            ->with(['user', 'decidedBy'])
            ->oldest('id')
            ->get();
    }

    private function assertListable(ClanJoinRequest $request): void
    {
        if (! $request->status->isOpen()) {
            throw new ClanRuleViolation(__('This request is no longer open.'));
        }
    }

    private function assertCaptain(Clan $clan, User $user): void
    {
        if (! $clan->isCaptain($user)) {
            throw new ClanRuleViolation(__('Only a captain of :clan can answer join requests.', ['clan' => $clan->name]));
        }
    }

    private function fresh(ClanJoinRequest $request): ClanJoinRequest
    {
        return ClanJoinRequest::query()->with(['clan', 'user'])->findOrFail($request->id);
    }
}
