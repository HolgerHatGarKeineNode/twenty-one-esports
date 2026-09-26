<?php

use App\Enums\ClanRole;
use App\Enums\InviteLinkType;
use App\Enums\InviteStatus;
use App\Enums\JoinRequestStatus;
use App\Enums\LineupRole;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanLogos;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Clans\ClanStats;
use App\Support\Clans\PortalMeetups;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
 * Manage a clan, from ClanManage.dc.html. Captains see it; only the founder
 * (owner) confirms player changes, because the clan event (kind 32150) and
 * the lineups the league publishes are signed with the founder's key.
 *
 * P4b: "Invite a player" brings a player into the roster only. Lineups are
 * built here from the active members; a member's clan membership is their
 * consent, so no player confirms anything for a lineup (NIP rev. 6).
 * Elo, tiers, Block Height, Clan Rating and Hashrate come from ClanStats (real).
 *
 * "Edit clan": the owner changes name, tag, description, logo and meetup
 * link with a new version of the clan event (same `d`, roster unchanged).
 */
new #[Layout('layouts::app', ['section' => 'clans'])] class extends Component
{
    use WithFileUploads;

    /** Rocket League modes in display order. */
    private const array MODES = ['3v3', '2v2', '1v1'];

    #[Locked]
    public int $clanId;

    /** The hex pubkey picked in <x-player-picker allow-npub>; null = nothing picked. */
    public ?string $player = null;

    /** The lineup mode being edited, null while no lineup is open. */
    #[Locked]
    public ?string $editing = null;

    /** @var array<int|string, string> user id => '' (not placed), captain, player or substitute */
    public array $picks = [];

    public string $tab = 'active';

    #[Locked]
    public ?int $confirmRemoval = null;

    #[Locked]
    public ?string $inviteLink = null;

    /** Join link (P6b): who may use it and how long it works. */
    public string $joinLinkUses = 'several';

    public int $joinLinkHours = 168;

    /** "Edit clan" (owner only): the card is open and holds these fields. */
    #[Locked]
    public bool $editingClan = false;

    public string $editName = '';

    public string $editClantag = '';

    public string $editDescription = '';

    /** The logo to keep: the current one, a portal meetup logo, or null. An upload replaces it. */
    #[Locked]
    public ?string $editPicture = null;

    public ?TemporaryUploadedFile $logo = null;

    public string $meetupQuery = '';

    #[Locked]
    public ?string $editMeetupName = null;

    #[Locked]
    public ?string $editMeetupCity = null;

    #[Locked]
    public ?string $editMeetupUrl = null;

    #[Locked]
    public ?float $editMeetupLatitude = null;

    #[Locked]
    public ?float $editMeetupLongitude = null;

    /** The linked meetup's portal logo, once known (picked now, or looked up). */
    #[Locked]
    public ?string $editMeetupLogo = null;

    public function mount(Clan $clan): void
    {
        abort_unless($clan->isCaptain($this->user()), 403);

        $this->clanId = $clan->id;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Manage :clan', ['clan' => $this->clan->name]));
    }

    #[Computed]
    public function clan(): Clan
    {
        return Clan::query()->with(['members.user', 'lineups.seats.user', 'lineups.clan', 'invites.invitee', 'departures.user'])->findOrFail($this->clanId);
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->clan->isOwner($this->user());
    }

    public function pickTab(string $tab): void
    {
        $this->tab = in_array($tab, ['active', 'invited', 'former'], true) ? $tab : 'active';
    }

    /* ---------- Edit the clan (owner only) ---------- */

    public function openEdit(): void
    {
        abort_unless($this->isOwner, 403);

        $clan = $this->clan;
        $this->editName = $clan->name;
        $this->editClantag = $clan->clantag;
        $this->editDescription = $clan->description ?? '';
        $this->editPicture = $clan->picture;
        $this->editMeetupName = $clan->meetup_name;
        $this->editMeetupCity = $clan->meetup_city;
        $this->editMeetupUrl = $clan->meetup_url;
        $this->editMeetupLatitude = $clan->meetup_latitude === null ? null : (float) $clan->meetup_latitude;
        $this->editMeetupLongitude = $clan->meetup_longitude === null ? null : (float) $clan->meetup_longitude;
        $this->reset('logo', 'meetupQuery', 'editMeetupLogo');
        $this->resetErrorBag();
        $this->editingClan = true;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingClan', 'logo', 'meetupQuery', 'editMeetupLogo');
        $this->resetErrorBag();
    }

    public function updatedEditClantag(): void
    {
        $this->editClantag = strtoupper(trim($this->editClantag));
    }

    /**
     * Checked as soon as the file arrives, so a wrong file never shows as a
     * preview and the error sits under the logo right away.
     */
    public function updatedLogo(): void
    {
        try {
            $this->validate(['logo' => ClanLogos::rules()], $this->logoMessages());
        } catch (ValidationException $invalid) {
            $this->logo = null;

            throw $invalid;
        }
    }

    public function removeLogo(): void
    {
        abort_unless($this->isOwner, 403);

        $this->logo = null;
        $this->editPicture = null;
        $this->resetErrorBag('logo');
    }

    public function useMeetupLogo(PortalMeetups $portal): void
    {
        abort_unless($this->isOwner, 403);

        $logo = $this->editMeetupLogo ?? ($this->editMeetupUrl === null ? null : ($portal->findByUrl($this->editMeetupUrl)['logo'] ?? null));

        if ($logo === null) {
            $this->addError('logo', __('The portal has no logo for this meetup right now.'));

            return;
        }

        $this->logo = null;
        $this->editPicture = $logo;
        $this->editMeetupLogo = $logo;
        $this->resetErrorBag('logo');
    }

    /**
     * Portal matches while the card is open; null when the portal is unreachable.
     *
     * @return list<array<string, mixed>>|null
     */
    #[Computed]
    public function meetups(): ?array
    {
        return $this->editingClan ? app(PortalMeetups::class)->search($this->meetupQuery) : [];
    }

    /**
     * Link a meetup. Name, text and logo stay as they are; the meetup's logo
     * is one click away ("Use meetup logo").
     */
    public function pickMeetup(int $id, PortalMeetups $portal): void
    {
        abort_unless($this->isOwner, 403);

        $meetup = $portal->find($id);

        if ($meetup === null) {
            return;
        }

        $this->editMeetupName = $meetup['name'];
        $this->editMeetupCity = $meetup['city'];
        $this->editMeetupUrl = $meetup['url'] !== '' ? $meetup['url'] : null;
        $this->editMeetupLatitude = $meetup['latitude'];
        $this->editMeetupLongitude = $meetup['longitude'];
        $this->editMeetupLogo = $meetup['logo'];
        $this->meetupQuery = '';
    }

    public function forgetMeetup(): void
    {
        abort_unless($this->isOwner, 403);

        $this->reset('editMeetupName', 'editMeetupCity', 'editMeetupUrl', 'editMeetupLatitude', 'editMeetupLongitude', 'editMeetupLogo');
    }

    /**
     * `free`, `taken`, `invalid` or `own` for the hint under the tag field.
     */
    #[Computed]
    public function editTagStatus(): ?string
    {
        if (! $this->editingClan || $this->editClantag === '') {
            return null;
        }

        if (preg_match(Clan::TAG_PATTERN, $this->editClantag) !== 1) {
            return 'invalid';
        }

        if ($this->editClantag === $this->clan->clantag) {
            return 'own';
        }

        return Clan::query()->where('clantag', $this->editClantag)->exists() ? 'taken' : 'free';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareEdit(ClanService $clans, ClanLogos $logos): ?array
    {
        $edit = $this->editDraft($logos);

        if ($edit === null) {
            return null;
        }

        // The upload is the logo the stored, signed clan event already names
        // (its file could not be written last time): write it, sign nothing.
        if ($edit['png'] !== null && $edit['draft']->picture === $this->clan->picture && $this->clan->event_id !== null
            && $edit['draft'] == $this->storedDraft()) {
            if ($logos->store($edit['png'])) {
                $this->cancelEdit();
                unset($this->clan);
            } else {
                $this->addError('logo', __('The clan was saved, but the logo could not be stored. Please upload it again.'));
            }

            return null;
        }

        $templates = $this->attempt(fn () => $clans->prepareEdit($this->user(), $this->clan, $edit['draft']), 'edit');

        return is_array($templates) ? $templates : null;
    }

    /**
     * The logo file is written only now, once the signed event is accepted
     * it points at it; the replaced upload is deleted when no clan uses it.
     */
    public function saveEdit(string $signed, ClanService $clans, ClanLogos $logos): void
    {
        $edit = $this->editDraft($logos);

        if ($edit === null) {
            return;
        }

        $draft = $edit['draft'];
        $previous = $this->clan->picture;

        // The URL in the event was computed from the PNG without writing it.
        if ($this->attempt(fn () => $clans->edit($this->user(), $this->clan, $draft, $this->signed($signed)), 'edit') === false) {
            return;
        }

        if ($previous !== $draft->picture) {
            $logos->deleteIfUnused($previous);
        }

        unset($this->clan);

        if ($edit['png'] !== null && ! $logos->store($edit['png'])) {
            $this->logo = null;
            $this->editPicture = null;
            $this->addError('logo', __('The clan was saved, but the logo could not be stored. Please upload it again.'));

            return;
        }

        $this->cancelEdit();
    }

    /**
     * The edited fields as a draft, with an uploaded logo rendered (its URL
     * in the draft, its PNG to store), or null with the errors on the card.
     *
     * @return array{draft: ClanDraft, png: string|null}|null
     */
    private function editDraft(ClanLogos $logos): ?array
    {
        abort_unless($this->isOwner && $this->editingClan, 403);

        $this->editClantag = strtoupper(trim($this->editClantag));

        $this->validate([
            'editName' => ['required', 'string', 'max:64'],
            'editClantag' => ['required', 'regex:'.Clan::TAG_PATTERN, Rule::unique('clans', 'clantag')->ignore($this->clanId)],
            'editDescription' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', ...ClanLogos::rules()],
        ], [
            'editClantag.regex' => __('2 to 4 capital letters or digits.'),
            'editClantag.unique' => __('The tag :tag is taken.', ['tag' => $this->editClantag]),
            ...$this->logoMessages(),
        ], [
            'editName' => __('name'),
            'editClantag' => __('tag'),
            'editDescription' => __('description'),
        ]);

        $png = null;
        $picture = $this->editPicture;

        if ($this->logo !== null) {
            try {
                $png = $logos->render($this->logo);
            } catch (ClanRuleViolation $unreadable) {
                $this->logo = null;
                $this->addError('logo', $unreadable->getMessage());

                return null;
            }

            $picture = $logos->urlFor($png);
        }

        return ['png' => $png, 'draft' => new ClanDraft(
            trim($this->editName),
            $this->editClantag,
            trim($this->editDescription) === '' ? null : trim($this->editDescription),
            $picture,
            $this->editMeetupName,
            $this->editMeetupCity,
            $this->editMeetupUrl,
            $this->editMeetupLatitude,
            $this->editMeetupLongitude,
        )];
    }

    /**
     * The clan as it is stored, as a draft (to tell "nothing changed" apart).
     */
    private function storedDraft(): ClanDraft
    {
        $clan = $this->clan;

        return new ClanDraft(
            $clan->name,
            $clan->clantag,
            $clan->description,
            $clan->picture,
            $clan->meetup_name,
            $clan->meetup_city,
            $clan->meetup_url,
            $clan->meetup_latitude === null ? null : (float) $clan->meetup_latitude,
            $clan->meetup_longitude === null ? null : (float) $clan->meetup_longitude,
        );
    }

    /**
     * @return array<string, string>
     */
    private function logoMessages(): array
    {
        return [
            'logo.image' => __('Use a PNG, JPG or WebP image. SVG is not supported.'),
            'logo.mimes' => __('Use a PNG, JPG or WebP image. SVG is not supported.'),
            'logo.max' => __('The logo can be at most 2 MB.'),
            'logo.dimensions' => __('The logo needs at least 64 × 64 and at most :max × :max pixels.', ['max' => ClanLogos::MAX_SIDE]),
        ];
    }

    /* ---------- Invite (into the roster) ---------- */

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareInvite(ClanService $clans): ?array
    {
        $invitee = $this->resolveInvitee();

        if ($invitee === null) {
            return null;
        }

        return $this->attempt(fn () => $clans->prepareInvite($this->user(), $this->clan, $invitee), 'player');
    }

    public function invite(string $signed, ClanService $clans): void
    {
        $invitee = $this->resolveInvitee();

        if ($invitee === null) {
            return;
        }

        $invite = $this->attempt(fn () => $clans->invite($this->user(), $this->clan, $invitee, $this->signed($signed)), 'player');

        if ($invite instanceof ClanInvite) {
            $this->inviteLink = route('invites.show', $invite);
            $this->player = null;
            unset($this->clan);
        }
    }

    /* ---------- Join link and join requests (P6b, NIP decision (a)) ---------- */

    public function createJoinLink(InviteLinks $links): void
    {
        try {
            $link = $links->create($this->user(), InviteLinkType::Clan, ['clan' => $this->clan, 'uses' => $this->joinLinkUses, 'hours' => $this->joinLinkHours]);
        } catch (InviteLinkRefused $refused) {
            $this->addError('joinLink', $refused->getMessage());

            return;
        }

        $this->redirectRoute('invites.link', $link);
    }

    /**
     * Requests still waiting for the clan, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, ClanJoinRequest>
     */
    #[Computed]
    public function joinRequests(): \Illuminate\Support\Collection
    {
        return app(ClanJoinRequests::class)->openRequests($this->clan);
    }

    /**
     * @return \Illuminate\Support\Collection<int, InviteLink>
     */
    #[Computed]
    public function joinLinks(): \Illuminate\Support\Collection
    {
        return InviteLink::query()->where('clan_id', $this->clanId)->where('type', InviteLinkType::Clan)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->with('inviter')->latest('id')->get();
    }

    /** A captain who is not the owner says yes; the owner lists the player next. */
    public function approveRequest(int $requestId, ClanJoinRequests $requests): void
    {
        $this->attempt(fn () => $requests->approve($this->joinRequest($requestId), $this->user()), 'joinRequests');
        unset($this->joinRequests);
    }

    public function declineRequest(int $requestId, ClanJoinRequests $requests): void
    {
        $this->attempt(fn () => $requests->decline($this->joinRequest($requestId), $this->user()), 'joinRequests');
        unset($this->joinRequests);
    }

    /**
     * The owner approves by listing the player in the clan event, signed
     * with their key (the named invite of ClanService).
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareListRequest(int $requestId, ClanJoinRequests $requests): ?array
    {
        $templates = $this->attempt(fn () => $requests->prepareList($this->joinRequest($requestId), $this->user()), 'joinRequests');

        return is_array($templates) ? $templates : null;
    }

    public function listRequest(int $requestId, string $signed, ClanJoinRequests $requests): void
    {
        $this->attempt(fn () => $requests->list($this->joinRequest($requestId), $this->user(), $this->signed($signed)), 'joinRequests');
        unset($this->joinRequests, $this->clan);
    }

    private function joinRequest(int $requestId): ClanJoinRequest
    {
        return ClanJoinRequest::query()->where('clan_id', $this->clanId)->findOrFail($requestId);
    }

    /* ---------- Lineups (from the roster) ---------- */

    public function editLineup(string $mode): void
    {
        abort_unless($this->isOwner && in_array($mode, self::MODES, true), 403);

        $lineup = $this->clan->lineups->first(fn (Lineup $lineup) => $lineup->game === 'rocket-league' && $lineup->mode === $mode);
        $this->picks = [];

        foreach ($this->clan->members as $member) {
            $this->picks[$member->user_id] = $lineup?->seats->firstWhere('user_id', $member->user_id)?->role->value ?? '';
        }

        $this->editing = $mode;
        $this->resetErrorBag('lineup');
    }

    public function cancelLineup(): void
    {
        $this->editing = null;
        $this->picks = [];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareLineup(ClanService $clans): ?array
    {
        $seats = $this->pickedSeats();

        return $seats === null ? null : $this->attempt(fn () => $clans->prepareLineup($this->user(), $this->clan, 'rocket-league', (string) $this->editing, $seats), 'lineup');
    }

    public function saveLineup(string $signed, ClanService $clans): void
    {
        $seats = $this->pickedSeats();

        if ($seats === null) {
            return;
        }

        if ($this->attempt(fn () => $clans->saveLineup($this->user(), $this->clan, 'rocket-league', (string) $this->editing, $seats, $this->signed($signed)), 'lineup') !== false) {
            $this->editing = null;
            $this->picks = [];
            unset($this->clan);
        }
    }

    /**
     * The picked seats as user id => role, or null (with an error) when no
     * lineup is open or a pick is not a role.
     *
     * @return array<int, LineupRole>|null
     */
    private function pickedSeats(): ?array
    {
        if ($this->editing === null) {
            $this->addError('lineup', __('Open a lineup first.'));

            return null;
        }

        $this->validate(['picks' => ['array'], 'picks.*' => ['nullable', Rule::in(['', 'captain', 'player', 'substitute'])]]);

        $seats = [];

        foreach ($this->picks as $userId => $role) {
            if ($role !== '' && $role !== null) {
                $seats[(int) $userId] = LineupRole::from($role);
            }
        }

        return $seats;
    }

    /* ---------- Captain, removal, leaving ---------- */

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareMakeCaptain(int $userId, ClanService $clans): ?array
    {
        return $this->attempt(fn () => $clans->prepareMakeCaptain($this->user(), $this->clan, $this->member($userId)));
    }

    public function makeCaptain(int $userId, string $signed, ClanService $clans): void
    {
        $this->attempt(fn () => $clans->makeCaptain($this->user(), $this->clan, $this->member($userId), $this->signed($signed)));
        unset($this->clan);
    }

    public function askRemoval(int $userId): void
    {
        $this->confirmRemoval = $this->clan->members->contains('user_id', $userId) ? $userId : null;
    }

    public function cancelRemoval(): void
    {
        $this->confirmRemoval = null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareRemove(int $userId, ClanService $clans): ?array
    {
        return $this->attempt(fn () => $clans->prepareRemove($this->user(), $this->clan, $this->member($userId)));
    }

    public function remove(int $userId, string $signed, ClanService $clans): void
    {
        $this->attempt(fn () => $clans->remove($this->user(), $this->clan, $this->member($userId), $this->signed($signed)));
        $this->confirmRemoval = null;
        unset($this->clan);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareLeave(ClanService $clans): ?array
    {
        return $this->attempt(fn () => $clans->prepareLeave($this->user()));
    }

    public function leave(string $signed, ClanService $clans): void
    {
        if ($this->attempt(fn () => $clans->leave($this->user(), $this->signed($signed))) !== false) {
            $this->redirectRoute('clans.index');
        }
    }

    /**
     * What removing the player does to the lineups (the red confirm panel).
     *
     * @return list<string>
     */
    #[Computed]
    public function removalEffects(): array
    {
        if ($this->confirmRemoval === null) {
            return [];
        }

        $effects = [];

        foreach ($this->clan->lineups as $lineup) {
            $seat = $lineup->seats->firstWhere('user_id', $this->confirmRemoval);

            if ($seat === null || ! $seat->role->countsTowardsMinimum() || $seat->accepted_at === null) {
                continue;
            }

            $left = $lineup->activeCount() - 1;
            $needed = $lineup->gameMode()->lineupMinimum();
            $effects[] = $left === 0
                ? __('your :mode has nobody left', ['mode' => $lineup->mode])
                : ($left < $needed ? __('your :mode drops to :n of :m players', ['mode' => $lineup->mode, 'n' => $left, 'm' => $needed]) : __('your :mode keeps :n of :m players', ['mode' => $lineup->mode, 'n' => $left, 'm' => $needed]));
        }

        return $effects;
    }

    /* ---------- Helpers ---------- */

    private function resolveInvitee(): ?User
    {
        // The picker (allow-npub) hands over a hex pubkey: a player here, or a key that has no account yet.
        $this->resetErrorBag('player');
        $pubkey = NostrKeys::toHex((string) $this->player);

        if ($pubkey === null) {
            $this->addError('player', __('Pick a player from the suggestions or paste a full npub.'));

            return null;
        }

        // Only the owner invites (ClanService::assertOwner); checked before a stub account for a new key is created.
        if ($this->clan->owner_id !== auth()->id()) {
            $this->addError('player', __('Only the founder of :clan can change its players.', ['clan' => $this->clan->name]));

            return null;
        }

        return User::query()->firstOrCreate(['pubkey' => $pubkey], ['npub' => NostrKeys::hexToNpub($pubkey)]);
    }

    private function member(int $userId): User
    {
        return $this->clan->members->firstWhere('user_id', $userId)?->user ?? throw new ClanRuleViolation(__('This player is not in the clan.'));
    }

    /**
     * @return list<mixed>
     */
    private function signed(string $json): array
    {
        return array_values((array) json_decode($json, true));
    }

    /**
     * Run a clan action; rule violations and refused signatures become form errors.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|false|null
     */
    private function attempt(callable $action, string $field = 'clan'): mixed
    {
        try {
            return $action() ?? true;
        } catch (ClanRuleViolation $violation) {
            $this->addError($field, $violation->getMessage());
        } catch (RejectedEvent) {
            $this->addError($field, __('The confirmation did not match. Please try again.'));
        }

        return false;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $clan = $this->clan;
    $tag = $clan->clantag;
    $isOwner = $this->isOwner;
    $modes = ['3v3', '2v2', '1v1'];
    $lineups = $clan->lineups->where('game', 'rocket-league')->sortBy(fn (Lineup $lineup) => array_search($lineup->mode, $modes, true))->values();
    $lead = $lineups->first();
    $clanStats = app(ClanStats::class);
    $leadStats = $lead ? $clanStats->lineup($lead) : null;
    $members = $clan->members->sortBy(fn (ClanMember $member) => [$member->user_id === $clan->owner_id ? 0 : ($member->role === ClanRole::Captain ? 1 : 2), $member->joined_at->getTimestamp()])->values();
    $pending = $clan->invites->where('status', InviteStatus::Pending)->values();
    $paid = $members->filter(fn ($member) => $member->user->is_member)->count();
    $rating = $clanStats->clanRating($clan);
    $hash = $clanStats->hashrate($clan);
    $heights = ClanStats::blockHeights($members->pluck('user_id'));
    $captains = $members->filter(fn ($member) => $member->role === ClanRole::Captain)->map(fn ($member) => $member->user->displayName().($member->user_id === $clan->owner_id ? ' ('.__('owner').')' : ''))->implode(', ');
    $messages = \App\Support\Nostr\SignerMessages::labels();
@endphp

<div class="mx-auto flex w-full max-w-[1232px] grow flex-col gap-5 px-4 pb-6"
     x-data="nostrAction({ pubkey: @js(auth()->user()->pubkey), messages: @js($messages) })">
    <div class="flex flex-wrap items-center gap-4">
        {{-- The clan logo, or the tag tile when there is none. --}}
        <x-clan-tag :clan="$clan" :tile="52" class="flex size-[52px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[linear-gradient(135deg,#F9B25F,#F7931A_55%,#B9640A)] font-display text-[15px] font-extrabold text-on-btc" />
        <span class="flex min-w-0 flex-col gap-1.5">
            <span class="flex flex-wrap items-center gap-3">
                <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ $clan->name }}</h1>
                @if ($leadStats)
                    <span class="flex h-[26px] items-center gap-1.5 rounded-md bg-[#0F1E26] px-2.5 text-xs"><x-rank-badge :tier="$leadStats['tier']" :level="$leadStats['level']" /><span class="text-rank-diamond">{{ __('in :mode', ['mode' => $lead->mode]) }}</span></span>
                @endif
                @if ($clan->isMemberClan())<x-member-badge long />@endif
            </span>
            <span class="flex items-center gap-2 text-[13px]">
                <span class="text-ink-2">{{ trans_choice(':count player|:count players', $members->count()) }}, {{ __(':n of them EINUNDZWANZIG members', ['n' => $paid]) }}</span>
                <button type="button" aria-label="{{ __('Copy clan link') }}" x-on:click="navigator.clipboard?.writeText(@js(route('clans.show', $clan)))"
                        class="flex size-11 cursor-pointer items-center justify-center rounded-md bg-well text-ink-2 hover:text-ink">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"></rect><path d="M5 15V5a2 2 0 0 1 2-2h10"></path></svg>
                </button>
            </span>
        </span>
        <span class="hidden grow lg:block"></span>
        <span class="flex gap-2">
            <button type="button" x-on:click="run('prepareLeave', 'leave')" x-bind:disabled="busy" class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-4 text-[13px] text-ink">{{ __('Leave clan') }}</button>
            <a href="{{ route('clans.show', $clan) }}" class="btn-w inline-flex h-11 items-center rounded-md border border-line bg-well px-4 text-[13px] whitespace-nowrap text-ink hover:text-ink">{{ __('Public clan page') }}</a>
        </span>
    </div>

    @error('clan')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>

    {{-- Edit clan: a new version of the clan event, same d, same roster (layout of ClanCreate.dc.html) --}}
    <section id="edit-clan" aria-labelledby="edit-h" class="flex flex-col gap-3.5 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="edit-clan"
             x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false"
             x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false">
        <span class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <span class="flex flex-wrap items-baseline gap-x-4 gap-y-1"><h2 id="edit-h" class="m-0 text-[15px] font-bold">{{ __('Edit clan') }}</h2><span class="text-xs text-ink-3">{{ __('Name, tag, description, logo and meetup link') }}</span></span>
            @if ($isOwner && ! $editingClan)
                <button type="button" wire:click="openEdit" data-test="open-edit" class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-4 text-[13px] text-ink">{{ __('Edit clan') }}</button>
            @endif
        </span>
        @if (! $isOwner)
            <p class="m-0 text-[13px] text-ink-2">{{ __('Only the founder of :clan can edit it: the clan record is confirmed with their key.', ['clan' => $clan->name]) }}</p>
        @elseif ($editingClan)
            @php($editMeetups = $this->meetups)
            <div class="grid grid-cols-1 gap-7 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="flex min-w-0 flex-col gap-[18px]">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                        <label class="flex min-w-0 flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Name') }}</span>
                            <input wire:model.blur="editName" required maxlength="64" data-test="edit-name" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink">
                            @error('editName')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="flex min-w-0 flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Tag, 2 to 4 characters') }}</span>
                            <input wire:model.live.debounce.300ms="editClantag" required maxlength="4" aria-describedby="edit-tag-hint" data-test="edit-clantag" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] font-bold text-ink uppercase">
                        </label>
                    </div>
                    <div id="edit-tag-hint" class="-mt-2.5 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 text-xs">
                        <span class="text-ink-2">{{ __('Your tag shows on ladders, rankings and match cards.') }}</span>
                        @switch($this->editTagStatus)
                            @case('free')<span class="flex items-center gap-1.5 text-win"><x-icon name="check" :size="14" />{{ __(':tag is free', ['tag' => $editClantag]) }}</span>@break
                            @case('taken')<span class="flex items-center gap-1.5 text-loss">{{ __(':tag is taken', ['tag' => $editClantag]) }}</span>@break
                            @case('invalid')<span class="text-loss">{{ __('2 to 4 capital letters or digits.') }}</span>@break
                            @default<span></span>
                        @endswitch
                        @if ($editClantag !== $clan->clantag)
                            <span class="col-span-full text-ink-3">{{ __('The clan address stays the same. Links that use the old tag :tag stop working.', ['tag' => $clan->clantag]) }}</span>
                        @endif
                        @error('editClantag')<span class="col-span-full text-loss" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Description, public') }}</span>
                        <textarea wire:model.blur="editDescription" rows="3" maxlength="1000" data-test="edit-description" class="h-24 w-full resize-y rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] leading-normal text-ink"></textarea>
                        @error('editDescription')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>

                    {{-- Meetup link: re-pick from the portal, or unlink --}}
                    <div class="flex flex-col gap-2.5 rounded-md bg-ground p-4 shadow-ring">
                        <label for="edit-meetup" class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-[13px]"><b>{{ __('Meetup') }}</b><span class="text-ink-3">{{ __('optional, links the clan to a meetup of the EINUNDZWANZIG portal') }}</span></label>
                        @if ($editMeetupName)
                            <span class="flex flex-wrap items-center justify-between gap-x-3 text-[13px]" data-test="edit-meetup-linked">
                                <span class="min-w-0 truncate">{{ $editMeetupName }}@if ($editMeetupCity)<span class="text-ink-3">, {{ $editMeetupCity }}</span>@endif</span>
                                <button type="button" wire:click="forgetMeetup" class="min-h-11 cursor-pointer text-xs text-ink-2 hover:text-ink">{{ __('Remove link') }}</button>
                            </span>
                        @endif
                        <span class="relative block">
                            <x-icon name="search" :size="16" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
                            <input id="edit-meetup" type="search" wire:model.live.debounce.400ms="meetupQuery" placeholder="{{ __('Search meetup or city') }}" autocomplete="off"
                                   class="h-11 w-full rounded-lg border border-edge bg-card pr-3.5 pl-10 text-[13px] text-ink placeholder:text-ink-3">
                        </span>
                        @if ($editMeetups === null)
                            <span class="text-xs text-loss" role="status">{{ __('The portal does not answer right now.') }}</span>
                        @elseif (mb_strlen(trim($meetupQuery)) >= 2)
                            <span class="text-xs text-ink-3">{{ trans_choice(':count match in the portal|:count matches in the portal', count($editMeetups)) }}</span>
                            <ul class="m-0 flex list-none flex-col p-0">
                                @foreach ($editMeetups as $meetup)
                                    <li wire:key="edit-mu-{{ $meetup['id'] }}">
                                        <button type="button" wire:click="pickMeetup({{ $meetup['id'] }})" @class(['tr flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-sm px-2 text-left text-[13px]', 'text-btc' => $editMeetupUrl !== null && $editMeetupUrl === $meetup['url']])>
                                            <span class="truncate">{{ $meetup['name'] }}</span><span class="shrink-0 text-xs text-ink-3">{{ $meetup['city'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Logo: live preview of the upload, square like the stored file --}}
                <div class="flex flex-col gap-2">
                    <span class="text-xs text-ink-2">{{ __('logo') }}</span>
                    <span class="relative flex size-[132px] items-center justify-center overflow-hidden rounded-lg bg-[repeating-linear-gradient(135deg,#2A2016_0_8px,#1E1A12_8px_16px)] text-xs text-btc-hi" data-test="logo-preview">
                        @if ($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Preview of the new logo') }}" class="size-full object-cover" data-test="logo-preview-upload">
                        @elseif ($editPicture)
                            <img src="{{ $editPicture }}" alt="{{ __('Current logo') }}" class="size-full object-cover" loading="lazy">
                        @else
                            {{ __('no logo yet') }}
                        @endif
                        <span wire:loading.flex wire:target="logo" class="absolute inset-0 items-center justify-center bg-ground/80 text-xs text-ink">{{ __('Uploading…') }}</span>
                    </span>
                    <span class="text-xs leading-normal text-ink-3">{{ __('PNG, JPG or WebP, up to 2 MB. Cropped to a square.') }}</span>
                    @error('logo')<span class="text-xs text-loss" role="alert" data-test="logo-error">{{ $message }}</span>@enderror
                    <div class="mt-1 flex flex-col gap-2">
                        <label class="btn-w flex h-11 cursor-pointer items-center justify-center rounded-lg border border-edge bg-well px-3.5 text-[13px] text-ink focus-within:outline-2 focus-within:outline-btc">
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only" data-test="logo-input">
                            {{ $logo || $editPicture ? __('Replace image') : __('Upload image') }}
                        </label>
                        @if ($editMeetupUrl)
                            <button type="button" wire:click="useMeetupLogo" class="btn-w flex h-11 cursor-pointer items-center justify-center rounded-lg border border-edge bg-well px-3.5 text-[13px] text-ink">{{ __('Use meetup logo') }}</button>
                        @endif
                        @if ($logo || $editPicture)
                            <button type="button" wire:click="removeLogo" data-test="remove-logo" class="min-h-11 cursor-pointer text-xs text-ink-2 hover:text-ink">{{ __('Remove logo') }}</button>
                        @endif
                    </div>
                </div>
            </div>

            @error('edit')<p class="m-0 text-[13px] text-loss" role="alert" data-test="edit-error">{{ $message }}</p>@enderror

            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <button type="button" x-on:click="run('prepareEdit', 'saveEdit')" x-bind:disabled="busy || uploading" data-test="save-edit"
                        class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-[22px] text-sm font-bold whitespace-nowrap text-on-btc disabled:cursor-wait disabled:opacity-70">
                    <x-icon name="shield-check" :size="18" />
                    <span x-show="! busy">{{ __('Save changes') }}</span>
                    <span x-show="busy" x-cloak>{{ __('Confirm in your signer…') }}</span>
                </button>
                <button type="button" wire:click="cancelEdit" x-bind:disabled="busy" class="min-h-11 cursor-pointer text-[13px] text-ink-2 hover:text-ink">{{ __('Cancel') }}</button>
                <span class="basis-full text-xs leading-normal text-ink-2 sm:basis-auto">{{ __('You confirm a new clan record with your key. Members and lineups stay as they are.') }}</span>
            </div>
        @endif
    </section>

    {{-- Invite a player: into the roster only --}}
    <section id="invite" class="flex flex-col gap-3.5 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 class="m-0 text-[15px] font-bold">{{ __('Invite a player') }}</h2><span class="text-xs text-ink-3">{{ __('They join the clan roster. You place them in lineups afterwards.') }}</span></span>
        @if ($isOwner)
            <div class="grid grid-cols-1 items-end gap-3.5 lg:grid-cols-[minmax(0,1fr)_auto]">
                <x-player-picker id="invite-player" wire:model="player" allow-npub :label="__('Player')" :exclude="$members->pluck('user_id')->all()" :placeholder="__('Name, npub or profile link')"
                                 input-class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3" class="gap-2" />
                <button type="button" x-on:click="run('prepareInvite', 'invite')" x-bind:disabled="busy" data-test="send-invite"
                        class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-[22px] text-sm font-bold whitespace-nowrap text-on-btc disabled:cursor-wait disabled:opacity-70">
                    <x-icon name="shield-check" :size="18" />{{ __('Send invite') }}
                </button>
            </div>
            @error('player')<p class="m-0 text-xs text-loss" role="alert">{{ $message }}</p>@enderror
            @if ($inviteLink)
                <p class="m-0 flex flex-wrap items-center gap-2 text-xs text-win" role="status">
                    <x-icon name="check" :size="14" />{{ __('Invite ready. Send them this link:') }}
                    <span class="min-w-0 truncate text-ink" data-test="invite-link">{{ $inviteLink }}</span>
                    <button type="button" x-on:click="navigator.clipboard?.writeText(@js($inviteLink))" class="min-h-11 cursor-pointer text-btc">{{ __('Copy') }}</button>
                </p>
            @endif
            <span class="flex items-start gap-2 text-xs leading-normal text-ink-2"><x-icon name="alert" :size="14" class="mt-px shrink-0 text-btc-hi" />{{ __('Only the invited player can accept. One player, one clan: accepting also leaves their current clan.') }}</span>
        @else
            <p class="m-0 text-[13px] text-ink-2">{{ __('Only the founder of :clan can add or remove players: the clan record is confirmed with their key.', ['clan' => $clan->name]) }}</p>
        @endif
    </section>

    {{-- Join requests and the join link (P6b, States.dc.html "Join request, captain side") --}}
    @php($requests = $this->joinRequests)
    <section id="join-requests" aria-labelledby="jr-h" class="flex flex-col gap-3.5 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="join-requests">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 id="jr-h" class="m-0 text-[15px] font-bold">{{ __('Join requests') }}</h2><span class="text-xs text-ink-3">{{ __(':clan, seen by captains only', ['clan' => $clan->name]) }}</span></span>
        @error('joinRequests')<p class="m-0 text-xs text-loss" role="alert">{{ $message }}</p>@enderror
        @forelse ($requests as $request)
            @php($applicant = $request->user)
            <div wire:key="jr-{{ $request->id }}" class="grid grid-cols-1 items-center gap-3 rounded-md px-3 py-3 shadow-ring lg:grid-cols-[minmax(0,1fr)_auto]" data-test="join-request">
                <span class="flex min-w-0 items-center gap-3">
                    <x-avatar :user="$applicant" :size="40" class="rounded-md" />
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <x-player-link :user="$applicant" class="inline-flex min-h-11 max-w-full items-center text-[15px] font-bold"><span class="truncate">{{ $applicant->displayName() }}</span></x-player-link>
                        <span class="text-xs leading-normal text-ink-2">
                            {{ trans_choice('Joined :count day ago|Joined :count days ago', (int) $applicant->created_at?->diffInDays(now())) }},
                            {{ trans_choice(':count game played|:count games played', $applicant->whiteGames()->count() + $applicant->blackGames()->count()) }}.
                            {{ __('Asked :time.', ['time' => $request->created_at?->diffForHumans()]) }}
                        </span>
                        @if ($request->status === JoinRequestStatus::Approved)
                            <span class="text-xs text-btc-hi" data-test="join-request-approved">{{ __('Approved by :name. Waiting for the founder to add them to the clan record.', ['name' => $request->decidedBy?->displayName() ?? '']) }}</span>
                        @endif
                    </span>
                </span>
                <span class="flex flex-wrap gap-2 lg:justify-end">
                    @if ($isOwner)
                        <button type="button" x-on:click="run('prepareListRequest', 'listRequest', {{ $request->id }})" x-bind:disabled="busy" data-test="approve-request"
                                class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold text-on-btc disabled:cursor-wait disabled:opacity-70">
                            <x-icon name="shield-check" :size="16" />{{ __('Approve') }}
                        </button>
                    @elseif ($request->status === JoinRequestStatus::Pending)
                        <button type="button" wire:click="approveRequest({{ $request->id }})" data-test="approve-request"
                                class="btn-p inline-flex h-11 cursor-pointer items-center justify-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc">{{ __('Approve') }}</button>
                    @endif
                    <button type="button" wire:click="declineRequest({{ $request->id }})" data-test="decline-request"
                            class="btn-w inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well px-4 text-[13px] text-ink">{{ __('Decline') }}</button>
                </span>
            </div>
        @empty
            <p class="m-0 text-[13px] text-ink-2">{{ __('No open requests. Share a join link and new players can ask to join.') }}</p>
        @endforelse
        <span class="text-xs leading-normal text-ink-3">{{ $isOwner
            ? __('Approving adds the player to the clan record with your key; they join once they confirm their membership. Declining tells them plainly and closes the request.')
            : __('Your yes goes to the founder, whose key adds the player to the clan record. Declining tells them plainly and closes the request.') }}</span>

        <div class="flex flex-col gap-3 border-t border-hairline pt-4" data-test="join-link">
            <span class="flex flex-col gap-1"><b class="text-[13px]">{{ __('Invite by link') }}</b><span class="text-xs leading-normal text-ink-2">{{ __('A join link for the roster. Everyone who uses it sends a request; a captain confirms each one. Lineups are set later, here.') }}</span></span>
            <div class="grid grid-cols-1 items-end gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Who can use it') }}</span>
                    <select wire:model="joinLinkUses" class="h-11 w-full rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                        <option value="several">{{ __('Several players, one request each') }}</option>
                        <option value="once">{{ __('One player') }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Link works for') }}</span>
                    <select wire:model.number="joinLinkHours" class="h-11 w-full rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                        @foreach (InviteLinkType::Clan->expiryChoices() as $hours)
                            <option value="{{ $hours }}">{{ trans_choice(':count day|:count days', intdiv($hours, 24)) }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" wire:click="createJoinLink" data-test="create-join-link"
                        class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc">
                    <x-icon name="link" :size="16" />{{ __('Create join link') }}
                </button>
            </div>
            @error('joinLink')<p class="m-0 text-xs text-loss" role="alert">{{ $message }}</p>@enderror
            @foreach ($this->joinLinks as $joinLink)
                <a wire:key="jl-{{ $joinLink->id }}" href="{{ $joinLink->url() }}" class="flex min-h-11 flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-hairline text-xs text-ink hover:text-ink">
                    <span class="flex items-center gap-2"><x-icon name="link" :size="14" class="text-btc" />{{ __('Join link by :name', ['name' => $joinLink->inviter->displayName()]) }}</span>
                    <span class="text-ink-2">{{ trans_choice('{0} not used yet|{1} used once|[2,*] used :count times', $joinLink->uses) }}, {{ __('open until :time', ['time' => $joinLink->expires_at->translatedFormat('M j, H:i')]) }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Players'), trans_choice(':count active|:count active', $members->count()).', '.trans_choice(':count invite open|:count invites open', $pending->count()), 'text-ink'],
                [__('Captains'), $captains, 'text-ink'],
                [__('Founded'), $clan->created_at?->translatedFormat('M j'), 'text-ink'],
                [__('Clan Rating, chess'), ! $clanStats->seasonLive() ? __('starts at Block 0') : ($rating['rating'] ? __(':n, average of the top 3 players', ['n' => $rating['rating']]) : __('needs 3 blitz Elos')), 'text-ink'],
                [__('Hashrate'), ! $clanStats->seasonLive() ? __('starts at Block 0') : __(':season this season, :week in the last 7 days', ['season' => $hash['season'], 'week' => $hash['week']]), 'text-btc'],
            ] as [$key, $value, $colour])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-sm lg:grid-cols-[160px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="{{ $colour }}">{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach (['3v3', '2v2', '1v1'] as $statsMode)
                @php($stats = ($statsLineup = $lineups->firstWhere('mode', $statsMode)) ? $clanStats->lineup($statsLineup) : null)
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-sm lg:grid-cols-[160px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Elo :mode', ['mode' => $statsMode]) }}</span>
                    <span>{{ $lineups->firstWhere('mode', $statsMode) === null ? __('no lineup yet') : $stats['elo'].', '.($stats['tier'] === 'provisional' ? ($stats['series'] === 0 ? __('Provisional, no series yet') : __('Provisional, :n series', ['n' => $stats['series']])) : __(':tier, rank :rank of :of', ['tier' => __(ucfirst($stats['tier'])).' '.['I', 'II', 'III'][$stats['level'] - 1], 'rank' => $stats['rank'], 'of' => $stats['of']])) }}</span></div>
            @endforeach
            <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-sm lg:grid-cols-[160px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Meetup') }}</span><span>{{ $clan->meetup_name ?? '–' }}</span></div>
            <div class="pt-3 pb-2">
                <x-proof :rows="[[__('Clan record'), $clan->naddr().', kind 32150'], [__('Last updated'), $clan->updated_at?->diffForHumans().' '.__('by :npub', ['npub' => \Illuminate\Support\Str::limit(NostrKeys::hexToNpub($clan->owner_pubkey), 10, '…')])], [__('Members'), trans_choice(':count membership record, kind 12150|:count membership records, kind 12150', $members->count())]]" />
            </div>
        </div>
    </div>

    {{-- Lineups & members --}}
    <section class="flex flex-col gap-2.5 rounded-lg bg-card px-4 py-5 lg:px-6">
        <div class="flex flex-wrap items-end gap-x-6 border-b border-hairline">
            <h2 class="m-0 pb-3 font-display text-lg font-bold">{{ __('Lineups & members') }}</h2>
            <span role="tablist" aria-label="{{ __('Filter') }}" class="flex gap-1">
                @foreach (['active' => __('Active (:n)', ['n' => $members->count()]), 'invited' => __('Invited (:n)', ['n' => $pending->count()]), 'former' => __('Former (:n)', ['n' => $clan->departures->count()])] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="pickTab('{{ $key }}')"
                            @class(['h-11 cursor-pointer border-b-2 px-3 text-[13px] lg:px-3.5', 'border-btc text-ink' => $tab === $key, 'border-transparent text-ink-2' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </span>
        </div>

        @if ($tab === 'active')
            <div class="hidden grid-cols-[110px_minmax(0,1fr)_190px_100px_auto] gap-3 px-2 pt-1 text-xs text-ink-3 lg:grid"><span>{{ __('Lineup') }}</span><span>{{ __('Seats') }}</span><span>{{ __('Status') }}</span><span>Elo</span><span></span></div>
            @foreach ($modes as $rowMode)
                @php($lineup = $lineups->firstWhere('mode', $rowMode))
                @php($stats = $lineup ? $clanStats->lineup($lineup) : null)
                @php($needed = app(GameRegistry::class)->mode('rocket-league', $rowMode)?->lineupMinimum() ?? 1)
                @php($active = $lineup?->activeCount() ?? 0)
                <div wire:key="lu-{{ $rowMode }}" class="tr grid min-h-[60px] grid-cols-[104px_minmax(0,1fr)] items-center gap-3 rounded-sm border-b border-hairline p-2 lg:grid-cols-[110px_minmax(0,1fr)_190px_100px_auto]">
                    <span class="flex flex-col gap-0.5"><b class="text-[15px]">{{ $rowMode }}</b>@if ($lineup)<x-rank-badge :tier="$stats['tier']" :level="$stats['level']" class="font-normal" />@endif</span>
                    <span class="flex min-w-0 flex-wrap gap-2">
                        @foreach ($lineup?->activeSeats() ?? [] as $seat)
                            {{-- min-w-0 + truncate: a long display name (a real Nostr name, not
                                just a factory fixture) must not push this chip past the column's
                                width and eat into the 16px side gutter (RouteSweepTest). --}}
                            <span class="flex h-[34px] max-w-full min-w-0 items-center gap-1.5 rounded-md border border-line bg-well px-2.5 text-xs">
                                <span class="min-w-0 truncate">{{ $seat->user->displayName() }}</span>
                                @if ($seat->role !== LineupRole::Player)<span class="shrink-0 text-ink-3">{{ $seat->role->label() }}</span>@endif
                            </span>
                        @endforeach
                        @for ($open = $lineup?->activeCount() ?? 0; $open < $needed; $open++)
                            <span class="flex h-[34px] items-center rounded-md border border-dashed border-line px-2.5 text-xs text-ink-3">{{ __('open seat') }}</span>
                        @endfor
                    </span>
                    <span data-test="lineup-status-{{ $rowMode }}" @class(['col-start-2 text-[13px] lg:col-start-auto', 'text-win' => $active >= $needed, 'text-btc-hi' => $active < $needed && $lineup, 'text-ink-3' => ! $lineup])>
                        {{ ! $lineup ? __('no lineup yet') : ($active >= $needed ? __('ready, :n of :m', ['n' => $active, 'm' => $needed]) : __('needs players, :n of :m', ['n' => $active, 'm' => $needed])) }}
                    </span>
                    <b class="col-start-2 font-display text-[15px] lg:col-start-auto">{{ $lineup ? $stats['elo'] : '–' }}</b>
                    @if ($isOwner)
                        <span class="col-start-2 lg:col-start-auto">
                            @if ($editing !== $rowMode)
                                <button type="button" wire:click="editLineup('{{ $rowMode }}')" data-test="edit-lineup-{{ $rowMode }}" class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] whitespace-nowrap text-ink">{{ $lineup ? __('Edit lineup') : __('Set up lineup') }}</button>
                            @endif
                        </span>
                    @endif
                </div>

                @if ($isOwner && $editing === $rowMode)
                    @php($picked = collect($picks)->filter(fn ($role) => in_array($role, ['captain', 'player'], true))->count())
                    <div wire:key="builder-{{ $rowMode }}" role="group" aria-labelledby="builder-title" class="flex flex-col gap-3 rounded-md bg-well px-4 py-4 shadow-ring lg:px-5">
                        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <b id="builder-title" class="text-[15px]">{{ __('Who plays the :mode', ['mode' => $rowMode]) }}</b>
                            <span @class(['text-xs', 'text-win' => $picked >= $needed, 'text-btc-hi' => $picked < $needed])>{{ __(':n of :m players picked', ['n' => $picked, 'm' => $needed]) }}</span>
                        </span>
                        <span class="text-xs leading-normal text-ink-2">{{ __('Pick from your roster. Joining the clan is their yes, so nobody has to confirm again; anyone can leave the clan at any time.') }}</span>
                        <div class="flex max-w-[640px] flex-col">
                            @foreach ($members as $member)
                                <label wire:key="pick-{{ $rowMode }}-{{ $member->user_id }}" class="grid min-h-[52px] grid-cols-[minmax(0,1fr)_minmax(0,150px)] items-center gap-3 border-b border-hairline text-[13px]">
                                    <span class="truncate">{{ $member->user->displayName() }}@if ($member->user->is(auth()->user())) ({{ __('you') }})@endif</span>
                                    <select wire:model="picks.{{ $member->user_id }}" data-test="pick-{{ $member->user_id }}" aria-label="{{ __('Seat of :name', ['name' => $member->user->displayName()]) }}" class="h-11 w-full min-w-0 appearance-none rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                                        <option value="">{{ __('Not picked') }}</option>
                                        <option value="captain">{{ __('Captain') }}</option>
                                        <option value="player">{{ __('Player') }}</option>
                                        <option value="substitute">{{ __('Sub') }}</option>
                                    </select>
                                </label>
                            @endforeach
                        </div>
                        @error('lineup')<p class="m-0 text-xs text-loss" role="alert">{{ $message }}</p>@enderror
                        @error('picks.*')<p class="m-0 text-xs text-loss" role="alert">{{ $message }}</p>@enderror
                        <span class="text-xs leading-normal text-ink-3">{{ __('A :mode needs :m players, captain included, to take challenges. Subs come on top.', ['mode' => $rowMode, 'm' => $needed]) }}</span>
                        <span class="flex flex-wrap justify-end gap-2.5">
                            <button type="button" wire:click="cancelLineup" class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-card px-4 text-[13px] text-ink">{{ __('Cancel') }}</button>
                            <button type="button" x-on:click="run('prepareLineup', 'saveLineup')" x-bind:disabled="busy" data-test="confirm-lineup"
                                    class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-[22px] text-sm font-bold whitespace-nowrap text-on-btc disabled:cursor-wait disabled:opacity-70">
                                <x-icon name="shield-check" :size="18" />{{ __('Confirm lineup') }}
                            </button>
                        </span>
                    </div>
                @endif
            @endforeach

            <div class="hidden grid-cols-[minmax(0,1fr)_180px_160px_300px] gap-3 px-2 pt-4 text-xs text-ink-3 lg:grid"><span>{{ __('Player') }}</span><span>{{ __('Role in clan') }}</span><span>{{ __('Lineups') }}</span><span></span></div>
            @foreach ($members as $member)
                @php($user = $member->user)
                @php($seats = $lineups->filter(fn (Lineup $lineup) => $lineup->seats->contains(fn (LineupSeat $seat) => $seat->user_id === $user->id && $seat->accepted_at !== null))->pluck('mode')->implode(', '))
                <div wire:key="mb-{{ $member->id }}" class="tr grid min-h-[52px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-sm border-b border-hairline px-2 py-1.5 text-[13px] lg:grid-cols-[minmax(0,1fr)_180px_160px_300px]">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="truncate">{{ $user->displayName() }}@if ($user->is(auth()->user())) ({{ __('you') }})@endif</b>
                        <span @class(['text-xs', 'text-btc' => $user->is_member, 'text-ink-3' => ! $user->is_member])>{{ $user->is_member ? __('EINUNDZWANZIG member') : __('Block Height :n', ['n' => $heights[$user->id] ?? 0]) }}</span>
                    </span>
                    <span class="hidden text-ink-2 lg:block">{{ $member->user_id === $clan->owner_id ? __('Owner, captain') : $member->role->label() }}</span>
                    <span class="hidden text-ink-2 lg:block">{{ $seats ?: '–' }}</span>
                    <span class="flex justify-end gap-2">
                        @if ($isOwner && $member->role !== ClanRole::Captain)
                            <button type="button" x-on:click="run('prepareMakeCaptain', 'makeCaptain', {{ $user->id }})" x-bind:disabled="busy"
                                    class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] whitespace-nowrap text-ink lg:px-4">
                                <x-icon name="shield-check" :size="16" /><span class="hidden sm:inline">{{ __('Make captain') }}</span><span class="sm:hidden">{{ __('Captain') }}</span>
                            </button>
                        @endif
                        @if ($isOwner && $member->user_id !== $clan->owner_id)
                            <button type="button" wire:click="askRemoval({{ $user->id }})" aria-label="{{ __('Remove :name from :clan', ['name' => $user->displayName(), 'clan' => $clan->name]) }}"
                                    class="inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] text-loss">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path></svg>
                            </button>
                        @endif
                    </span>
                </div>
            @endforeach

            @if ($confirmRemoval !== null && ($target = $members->firstWhere('user_id', $confirmRemoval)?->user))
                <div role="alertdialog" aria-labelledby="rm-title" class="grid grid-cols-1 items-center gap-4 rounded-md bg-[#1D1214] px-4 py-3.5 shadow-[inset_0_0_0_1px_#5A2A2E] lg:grid-cols-[minmax(0,1fr)_auto]">
                    <span class="flex flex-col gap-1 text-[13px] leading-normal">
                        <b id="rm-title" class="text-loss">{{ __('Remove :name from :clan?', ['name' => $target->displayName(), 'clan' => $clan->name]) }}</b>
                        <span class="text-ink-2">{{ $this->removalEffects === [] ? __('No lineup changes.') : ucfirst(implode(', ', $this->removalEffects)).'. '.__('A lineup below its size cannot accept challenges until the seats are filled.') }}</span>
                    </span>
                    <span class="flex gap-2.5">
                        <button type="button" wire:click="cancelRemoval" class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-4 text-[13px] text-ink">{{ __('Cancel') }}</button>
                        <button type="button" x-on:click="run('prepareRemove', 'remove', {{ $target->id }})" x-bind:disabled="busy"
                                class="inline-flex h-12 cursor-pointer items-center gap-2.5 rounded-md bg-loss px-[22px] text-sm font-bold whitespace-nowrap text-on-btc">
                            <x-icon name="shield-check" :size="18" />{{ __('Remove player') }}
                        </button>
                    </span>
                </div>
            @endif
        @elseif ($tab === 'invited')
            @forelse ($pending as $invite)
                <div wire:key="iv-{{ $invite->id }}" class="grid min-h-[52px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline px-2 text-[13px]">
                    <span class="flex min-w-0 flex-col gap-0.5"><b class="truncate">{{ $invite->invitee->displayName() }}</b><span class="text-xs text-ink-3">{{ __('into the roster, sent :when', ['when' => $invite->created_at?->diffForHumans()]) }}</span></span>
                    <button type="button" x-on:click="navigator.clipboard?.writeText(@js(route('invites.show', $invite)))" class="min-h-11 cursor-pointer text-xs text-btc">{{ __('Copy invite link') }}</button>
                </div>
            @empty
                <p class="m-0 py-4 text-[13px] text-ink-2">{{ __('No open invites.') }}</p>
            @endforelse
        @else
            @forelse ($clan->departures->sortByDesc('left_at') as $departure)
                <div wire:key="dp-{{ $departure->id }}" class="grid min-h-[52px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline px-2 text-[13px]">
                    <b class="truncate">{{ $departure->user?->displayName() ?? __('Deleted player') }}</b>
                    <span class="text-xs text-ink-3">{{ ['left' => __('left'), 'removed' => __('removed'), 'switched' => __('joined another clan'), 'deleted' => __('deleted their account')][$departure->reason] ?? $departure->reason }}, {{ $departure->left_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="m-0 py-4 text-[13px] text-ink-2">{{ __('Nobody has left yet.') }}</p>
            @endforelse
        @endif
    </section>
</div>
