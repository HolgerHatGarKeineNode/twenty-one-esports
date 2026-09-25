<?php

use App\Enums\ClanRole;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\User;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Clans\ClanStatsPreview;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Manage a clan, from ClanManage.dc.html. Captains see it; only the founder
 * (owner) confirms player changes, because the clan event (kind 32150) and
 * the lineups the league publishes are signed with the founder's key.
 *
 * P4b: "Invite a player" brings a player into the roster only. Lineups are
 * built here from the active members; a member's clan membership is their
 * consent, so no player confirms anything for a lineup (NIP rev. 6).
 * Elo, tiers, Clan Rating and Hashrate are ClanStatsPreview until P6/P7.
 */
new #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    /** Rocket League modes in display order. */
    private const array MODES = ['3v3', '2v2', '1v1'];

    #[Locked]
    public int $clanId;

    public string $player = '';

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
            $this->player = '';
            unset($this->clan);
        }
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
        $this->validate(['player' => ['required', 'string', 'max:200']]);

        $query = trim($this->player);
        $query = (string) preg_replace('#^.*/(npub1[0-9a-z]+).*$#', '$1', $query);
        $pubkey = NostrKeys::toHex($query);

        if ($pubkey !== null) {
            return User::query()->firstOrCreate(['pubkey' => $pubkey], ['npub' => NostrKeys::hexToNpub($pubkey)]);
        }

        $matches = User::query()->whereRaw('lower(name) = ?', [mb_strtolower($query)])->limit(2)->get();

        if ($matches->count() !== 1) {
            $this->addError('player', $matches->isEmpty() ? __('No player with that name. Paste their npub or profile link.') : __('Several players have that name. Paste their npub instead.'));

            return null;
        }

        return $matches->first();
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
    $leadStats = $lead ? ClanStatsPreview::lineup($tag, $lead->mode) : null;
    $members = $clan->members->sortBy(fn (ClanMember $member) => [$member->user_id === $clan->owner_id ? 0 : ($member->role === ClanRole::Captain ? 1 : 2), $member->joined_at->getTimestamp()])->values();
    $pending = $clan->invites->where('status', InviteStatus::Pending)->values();
    $paid = $members->filter(fn ($member) => $member->user->is_member)->count();
    $rating = ClanStatsPreview::clanRating($tag);
    $hash = ClanStatsPreview::hashrate($tag);
    $captains = $members->filter(fn ($member) => $member->role === ClanRole::Captain)->map(fn ($member) => $member->user->displayName().($member->user_id === $clan->owner_id ? ' ('.__('owner').')' : ''))->implode(', ');
    $messages = [
        'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
        'rejected' => __('The confirmation was not given. Please try again.'),
        'wrongKey' => __('This signer holds a different key than the one you logged in with.'),
        'failed' => __('That did not work. Please try again.'),
    ];
@endphp

<div class="mx-auto flex w-full max-w-[1200px] grow flex-col gap-5 px-4 pb-6 lg:px-0"
     x-data="nostrAction({ pubkey: @js(auth()->user()->pubkey), messages: @js($messages) })">
    <div class="flex flex-wrap items-center gap-4">
        {{-- Logo slot: the clan logo replaces the tag tile in the imagery pass. --}}
        <span class="flex size-[52px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[linear-gradient(135deg,#F9B25F,#F7931A_55%,#B9640A)] font-display text-[15px] font-extrabold text-on-btc">
            @if ($clan->picture)<img src="{{ $clan->picture }}" alt="" class="size-full object-cover" loading="lazy">@else{{ $tag }}@endif
        </span>
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

    {{-- Invite a player: into the roster only --}}
    <section id="invite" class="flex flex-col gap-3.5 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 class="m-0 text-[15px] font-bold">{{ __('Invite a player') }}</h2><span class="text-xs text-ink-3">{{ __('They join the clan roster. You place them in lineups afterwards.') }}</span></span>
        @if ($isOwner)
            <div class="grid grid-cols-1 items-end gap-3.5 lg:grid-cols-[minmax(0,1fr)_auto]">
                <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Player') }}</span>
                    <input type="search" wire:model="player" data-test="invite-player" placeholder="{{ __('Name, npub or profile link') }}" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
                </label>
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

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Players'), trans_choice(':count active|:count active', $members->count()).', '.trans_choice(':count invite open|:count invites open', $pending->count()), 'text-ink'],
                [__('Captains'), $captains, 'text-ink'],
                [__('Founded'), $clan->created_at?->translatedFormat('M j'), 'text-ink'],
                [__('Clan Rating, chess'), $rating['rating'] ? __(':n, average of the top 3 players', ['n' => $rating['rating']]) : __('needs 3 blitz Elos'), 'text-ink'],
                [__('Hashrate'), __(':season this season, :week in the last 7 days', ['season' => $hash['season'], 'week' => $hash['week']]), 'text-btc'],
            ] as [$key, $value, $colour])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-sm lg:grid-cols-[160px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="{{ $colour }}">{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach (['3v3', '2v2', '1v1'] as $statsMode)
                @php($stats = ClanStatsPreview::lineup($tag, $statsMode))
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-sm lg:grid-cols-[160px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Elo :mode', ['mode' => $statsMode]) }}</span>
                    <span>{{ $lineups->firstWhere('mode', $statsMode) === null ? __('no lineup yet') : $stats['elo'].', '.($stats['tier'] === 'provisional' ? __('Provisional, no series yet') : __(':tier, rank :rank of :of', ['tier' => __(ucfirst($stats['tier'])).' '.['I', 'II', 'III'][$stats['level'] - 1], 'rank' => $stats['rank'], 'of' => $stats['of']])) }}</span></div>
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
                @php($stats = ClanStatsPreview::lineup($tag, $rowMode))
                @php($needed = app(GameRegistry::class)->mode('rocket-league', $rowMode)?->lineupMinimum() ?? 1)
                @php($active = $lineup?->activeCount() ?? 0)
                <div wire:key="lu-{{ $rowMode }}" class="tr grid min-h-[60px] grid-cols-[104px_minmax(0,1fr)] items-center gap-3 rounded-sm border-b border-hairline p-2 lg:grid-cols-[110px_minmax(0,1fr)_190px_100px_auto]">
                    <span class="flex flex-col gap-0.5"><b class="text-[15px]">{{ $rowMode }}</b>@if ($lineup)<x-rank-badge :tier="$stats['tier']" :level="$stats['level']" class="font-normal" />@endif</span>
                    <span class="flex flex-wrap gap-2">
                        @foreach ($lineup?->activeSeats() ?? [] as $seat)
                            <span class="flex h-[34px] items-center gap-1.5 rounded-md border border-line bg-well px-2.5 text-xs">
                                {{ $seat->user->displayName() }}
                                @if ($seat->role !== LineupRole::Player)<span class="text-ink-3">{{ $seat->role->label() }}</span>@endif
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
                        <span @class(['text-xs', 'text-btc' => $user->is_member, 'text-ink-3' => ! $user->is_member])>{{ $user->is_member ? __('EINUNDZWANZIG member') : __('Block Height :n', ['n' => ClanStatsPreview::player((string) $user->name)['blockHeight']]) }}</span>
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
                    <b class="truncate">{{ $departure->user->displayName() }}</b>
                    <span class="text-xs text-ink-3">{{ ['left' => __('left'), 'removed' => __('removed'), 'switched' => __('joined another clan')][$departure->reason] ?? $departure->reason }}, {{ $departure->left_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="m-0 py-4 text-[13px] text-ink-2">{{ __('Nobody has left yet.') }}</p>
            @endforelse
        @endif
    </section>
</div>
