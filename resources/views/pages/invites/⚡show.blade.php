<?php

use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Models\ClanInvite;
use App\Models\LineupSeat;
use App\Models\User;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Clans\ClanStatsPreview;
use App\Support\Nostr\RejectedEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Clan invite, 1:1 from InviteAccept.dc.html. The invitee joins only by
 * confirming (signing) their own Clan Membership (kind 12150) that names the
 * clan and the lineup; declining needs no signature. The inviting clan's
 * captains may look at the invite; nobody else. Trust and games played come
 * with P7 (trust ranks) and P6 (series); until then those rows say so.
 */
new #[Title('Clan invite')] #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    #[Locked]
    public int $inviteId;

    public function mount(ClanInvite $invite): void
    {
        $user = $this->user();
        abort_unless($invite->invitee_id === $user->id || $invite->clan->isCaptain($user), 403);

        $this->inviteId = $invite->id;
    }

    #[Computed]
    public function invite(): ClanInvite
    {
        return ClanInvite::query()->with(['clan.members.user', 'lineup.seats.user', 'lineup.clan', 'inviter', 'invitee.clanMember.clan'])->findOrFail($this->inviteId);
    }

    public function isInvitee(): bool
    {
        return $this->invite->invitee_id === Auth::id();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAccept(ClanService $clans): ?array
    {
        try {
            return $clans->prepareAccept($this->invite, $this->user());
        } catch (ClanRuleViolation $violation) {
            $this->addError('invite', $violation->getMessage());

            return null;
        }
    }

    public function accept(string $signed, ClanService $clans): void
    {
        try {
            $clans->accept($this->invite, $this->user(), array_values((array) json_decode($signed, true)));
        } catch (ClanRuleViolation $violation) {
            $this->addError('invite', $violation->getMessage());

            return;
        } catch (RejectedEvent) {
            $this->addError('invite', __('The confirmation did not match. Please try again.'));

            return;
        }

        $this->redirectRoute('clans.show', $this->invite->clan);
    }

    public function decline(ClanService $clans): void
    {
        try {
            $clans->decline($this->invite, $this->user());
        } catch (ClanRuleViolation $violation) {
            $this->addError('invite', $violation->getMessage());
        }

        unset($this->invite);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $invite = $this->invite;
    $clan = $invite->clan;
    $lineup = $invite->lineup;
    $invitee = $invite->invitee;
    $inviter = $invite->inviter;
    $mine = $this->isInvitee();
    $stats = ClanStatsPreview::lineup($clan->clantag, $lineup->mode);
    $roleText = $invite->role === LineupRole::Substitute ? __('Sub') : $invite->role->label();
    $others = $lineup->seats->reject(fn (LineupSeat $seat) => $seat->user_id === $invitee->id);
    $lineupCaptain = $others->firstWhere('role', LineupRole::Captain)?->user ?? $clan->owner;
    $players = $others->filter(fn (LineupSeat $seat) => $seat->role === LineupRole::Player && $seat->isActive($clan->id))->map(fn ($seat) => $seat->user->displayName())->implode(', ');
    $current = $invitee->clanMember?->clan;
    $days = (int) $invitee->created_at?->diffInDays(now());
    $status = match ($invite->status) {
        InviteStatus::Pending => [__('waiting for your answer'), 'border-btc-deep text-btc bg-btc-chip'],
        InviteStatus::Accepted => [__('accepted'), 'border-win-ring text-win bg-win-tint'],
        default => [__('closed'), 'border-line text-ink-2 bg-well'],
    };
    $membershipTags = [['kind', '12150 '.__('clan membership')], ['a', $clan->address()], ['a', $lineup->address()], ['p', __('signed by :name', ['name' => $invitee->displayName()])]];
@endphp

<div class="mx-auto flex w-full max-w-[1200px] grow flex-col gap-5 px-4 py-6 lg:px-0"
     x-data="nostrAction({ pubkey: @js(auth()->user()->pubkey), messages: @js([
         'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
         'rejected' => __('The confirmation was not given. Please try again.'),
         'wrongKey' => __('This signer holds a different key than the one you logged in with.'),
         'failed' => __('That did not work. Please try again.'),
     ]) })">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __('Clan invite') }}</h1>
        <span class="text-[13px] text-ink-2">{{ __(':clan wants you as :role', ['clan' => $clan->name, 'role' => $invite->role === LineupRole::Substitute ? __('a sub') : __('a player')]) }}</span>
        <span class="hidden grow lg:block"></span>
        <span class="inline-flex h-[34px] items-center gap-2 rounded-md border px-3 text-[13px] font-bold {{ $status[1] }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"></path></svg>{{ $status[0] }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-lg bg-card px-4 lg:px-6">
            @foreach ([[__('Invited by'), ($inviter?->displayName() ?? '–').', '.__('captain')], [__('Sent'), $invite->created_at?->isToday() ? __('today :time', ['time' => $invite->created_at->format('H:i')]) : $invite->created_at?->translatedFormat('M j, H:i')], [__('Role'), __(':role in the :mode', ['role' => $roleText, 'mode' => $lineup->mode])]] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-sm lg:grid-cols-[170px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="rounded-lg bg-card px-4 lg:px-6">
            <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-sm lg:grid-cols-[170px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Game') }}</span><span>Rocket League, {{ $lineup->mode }}</span></div>
            <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-sm lg:grid-cols-[170px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Ladder') }}</span><span>{{ $stats['series'] === 0 ? __(':mode, Season 1, no series yet', ['mode' => $lineup->mode]) : __(':mode, Season 1, rank :rank of :of', ['mode' => $lineup->mode, 'rank' => $stats['rank'], 'of' => $stats['of']]) }}</span></div>
            <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-sm lg:grid-cols-[170px_minmax(0,1fr)]"><span class="text-ink-2">{{ __('Captain trust') }}</span>
                <span @class(['text-win' => $inviter?->is_member, 'text-ink-2' => ! $inviter?->is_member])>{{ $inviter?->is_member ? __('EINUNDZWANZIG member') : __('shown once rated games start') }}</span></div>
        </div>
    </div>

    <div class="grid grid-cols-1 items-center gap-5 lg:grid-cols-[minmax(0,1fr)_150px_minmax(0,1fr)] lg:gap-0">
        <div class="rounded-lg bg-card px-4 py-4 lg:px-6">
            <div class="flex items-center gap-3 pb-2">
                {{-- Avatar slot: the Nostr picture goes here in the imagery pass. --}}
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-raised font-display text-base font-extrabold">{{ $invitee->initials() }}</span>
                <span class="flex min-w-0 flex-col gap-0.5"><b class="truncate text-[15px]">{{ $invitee->displayName() }}@if ($mine) ({{ __('you') }})@endif</b>
                    <span class="text-xs text-ink-2">{{ trans_choice('account :count day old|account :count days old', $days) }}</span></span>
            </div>
            @foreach ([[__('Clan now'), $current?->name ?? __('none yet')], [__('Trust'), __('shown once rated games start')], [__('Account'), trans_choice(':count day old|:count days old', $days)], [__('Games played'), __('none rated yet')]] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px] lg:grid-cols-[142px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="hidden flex-col items-center gap-1 text-xs lg:flex" aria-hidden="true">
            <span class="text-btc">{{ __('joins') }}</span>
            <span class="block h-0.5 w-full bg-[repeating-linear-gradient(90deg,#F7931A_0_6px,transparent_6px_10px)]"></span>
            <span class="text-ink-2">{{ __('as :role', ['role' => mb_strtolower($roleText)]) }}</span>
        </div>
        <div class="rounded-lg bg-card px-4 py-4 lg:px-6">
            <div class="flex items-start justify-between gap-3 pb-2">
                <span class="flex min-w-0 items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[linear-gradient(135deg,#F9B25F,#F7931A_55%,#B9640A)] text-xs font-bold text-on-btc">
                        @if ($clan->picture)<img src="{{ $clan->picture }}" alt="" class="size-full object-cover" loading="lazy">@else{{ $clan->clantag }}@endif
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5"><b class="truncate text-[15px]">{{ __(':clan, :mode lineup', ['clan' => $clan->name, 'mode' => $lineup->mode]) }}</b>
                        <x-rank-badge :tier="$stats['tier']" :level="$stats['level']" class="font-normal" /></span>
                </span>
                @if ($clan->isMemberClan())<x-member-badge long class="hidden sm:inline-flex" />@endif
            </div>
            @foreach ([[__('Captain'), $lineupCaptain?->displayName() ?? '–', 'text-ink'], [__('Players'), $players ?: '–', 'text-ink'], [$roleText, $mine ? __('you, once you accept') : $invitee->displayName(), 'text-btc'], ['Elo', (string) $stats['elo'], 'text-ink']] as [$key, $value, $colour])
                <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px] lg:grid-cols-[142px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="{{ $colour }}">{{ $value }}</span></div>
            @endforeach
        </div>
    </div>

    <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 class="m-0 text-[15px] font-bold">{{ __('What you can play after joining') }}</h2><span class="text-xs text-ink-3">{{ __('joining a clan does not change who you are connected with') }}</span></span>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="flex gap-3 rounded-lg p-4 shadow-ring">
                <span class="mt-1.5 size-4 shrink-0 rounded-full border-4 border-btc bg-ground" aria-hidden="true"></span>
                <span class="flex flex-col gap-2"><b class="font-display text-lg">{{ __('Casual') }}</b><span class="text-[13px] leading-[1.6] text-ink-2">{{ __('Scrims with any clan, right away. No rating change, no connection needed.') }}</span></span>
            </div>
            <div class="flex gap-3 rounded-lg p-4 shadow-ring">
                <span class="mt-1.5 size-4 shrink-0 rounded-full bg-ink-3" aria-hidden="true"></span>
                <span class="flex flex-col gap-2"><b class="font-display text-lg text-ink-2">{{ __('Rated') }}</b><span class="text-[13px] leading-[1.6] text-ink-2">{{ __('Rated games need a Trusted account and a connection with the other captain. Casual games with members, and players who add you back, raise your trust.') }}</span></span>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 gap-5 rounded-lg bg-card px-4 py-5 lg:grid-cols-[minmax(0,1fr)_330px] lg:gap-8 lg:px-6">
        <div class="flex flex-col gap-3">
            <h2 class="m-0 text-[15px] font-bold">{{ __('What happens when you accept') }}</h2>
            <p class="m-0 text-[13px] leading-[1.6] text-ink-2">
                {{ __('You become :role in the :clan :mode.', ['role' => $invite->role === LineupRole::Substitute ? __('a sub') : __('a player'), 'clan' => $clan->name, 'mode' => $lineup->mode]) }}
                {{ $current && $current->id !== $clan->id ? __('You leave :clan: you can be in one clan at a time.', ['clan' => $current->name]) : __('You can be in one clan at a time.') }}
            </p>
            <x-proof :rows="$membershipTags" />
        </div>
        <div class="flex flex-col gap-2.5">
            @error('invite')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
            @if ($mine && $invite->isPending())
                <button type="button" x-on:click="run('prepareAccept', 'accept')" x-bind:disabled="busy"
                        class="btn-p inline-flex h-[52px] cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-7 text-[15px] font-bold text-on-btc disabled:cursor-wait disabled:opacity-70">
                    <x-icon name="shield-check" :size="18" />{{ __('Confirm and join') }}
                </button>
                <button type="button" wire:click="decline" class="btn-w inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-[13px] text-ink">{{ __('Decline') }}</button>
                <span class="text-center text-xs leading-normal text-ink-3">{{ $current ? __('Declining closes the invite. You stay in :clan.', ['clan' => $current->name]) : __('Declining closes the invite. You stay without a clan.') }}</span>
            @elseif ($invite->isPending())
                <p class="m-0 text-[13px] text-ink-2">{{ __('Waiting for :name to answer.', ['name' => $invitee->displayName()]) }}</p>
            @else
                <p class="m-0 text-[13px] text-ink-2">{{ $invite->status === InviteStatus::Accepted ? __('This invite was accepted.') : __('This invite is closed.') }}</p>
                <a href="{{ route('clans.show', $clan) }}" class="text-[13px]">{{ __('Go to :clan', ['clan' => $clan->name]) }}</a>
            @endif
        </div>
    </section>
</div>
