<?php

namespace App\Support\Clans;

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
 */
final class ClanJoinRequests
{
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
