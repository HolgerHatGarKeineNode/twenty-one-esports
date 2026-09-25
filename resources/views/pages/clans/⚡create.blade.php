<?php

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Clans\PortalMeetups;
use App\Support\Nostr\RejectedEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Start a clan, 1:1 from ClanCreate.dc.html. The player confirms (signs) the
 * clan event (kind 32150) and their own membership (kind 12150) in one step;
 * the server rebuilds both from these fields and checks the signatures
 * (ClanService). The portal import is optional and the page works without it.
 */
new #[Title('Start a clan')] #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    public string $meetupQuery = '';

    public string $name = '';

    public string $clantag = '';

    public string $description = '';

    #[Locked]
    public ?int $meetupId = null;

    #[Locked]
    public ?string $picture = null;

    #[Locked]
    public ?string $meetupName = null;

    #[Locked]
    public ?string $meetupCity = null;

    #[Locked]
    public ?string $meetupUrl = null;

    #[Locked]
    public ?float $meetupLatitude = null;

    #[Locked]
    public ?float $meetupLongitude = null;

    public function updatedClantag(): void
    {
        $this->clantag = strtoupper(trim($this->clantag));
    }

    /**
     * Portal matches for the query; null when the portal is unreachable.
     *
     * @return list<array<string, mixed>>|null
     */
    #[Computed]
    public function meetups(): ?array
    {
        return app(PortalMeetups::class)->search($this->meetupQuery);
    }

    public function pickMeetup(int $id): void
    {
        $meetup = app(PortalMeetups::class)->find($id);

        if ($meetup === null) {
            return;
        }

        $this->meetupId = $meetup['id'];
        $this->name = $meetup['name'];
        $this->description = $meetup['intro'] ?? $this->description;
        $this->picture = $meetup['logo'];
        $this->meetupName = $meetup['name'];
        $this->meetupCity = $meetup['city'];
        $this->meetupUrl = $meetup['url'] !== '' ? $meetup['url'] : null;
        $this->meetupLatitude = $meetup['latitude'];
        $this->meetupLongitude = $meetup['longitude'];

        if ($this->clantag === '') {
            $this->clantag = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $meetup['city'] ?: $meetup['name']), 0, 3));
        }
    }

    public function forgetMeetup(): void
    {
        $this->reset('meetupId', 'picture', 'meetupName', 'meetupCity', 'meetupUrl', 'meetupLatitude', 'meetupLongitude');
    }

    /**
     * `free`, `taken` or `invalid` for the hint under the tag field.
     */
    #[Computed]
    public function tagStatus(): ?string
    {
        if ($this->clantag === '') {
            return null;
        }

        if (preg_match(Clan::TAG_PATTERN, $this->clantag) !== 1) {
            return 'invalid';
        }

        return Clan::query()->where('clantag', $this->clantag)->exists() ? 'taken' : 'free';
    }

    #[Computed]
    public function currentClan(): ?Clan
    {
        return $this->user()->clanMember?->clan;
    }

    /**
     * The unsigned events to confirm, or null (errors are on the form).
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareCreate(ClanService $clans): ?array
    {
        $this->validateDraft();

        try {
            return $clans->prepareCreate($this->user(), $this->draft());
        } catch (ClanRuleViolation $violation) {
            $this->addError('clan', $violation->getMessage());

            return null;
        }
    }

    public function create(string $signed, ClanService $clans): void
    {
        $this->validateDraft();

        try {
            $clan = $clans->create($this->user(), $this->draft(), (array) json_decode($signed, true));
        } catch (ClanRuleViolation $violation) {
            $this->addError('clan', $violation->getMessage());

            return;
        } catch (RejectedEvent) {
            $this->addError('clan', __('The confirmation did not match. Please try again.'));

            return;
        }

        $this->redirectRoute('clans.show', $clan);
    }

    private function validateDraft(): void
    {
        $this->clantag = strtoupper(trim($this->clantag));

        $this->validate([
            'name' => ['required', 'string', 'max:64'],
            'clantag' => ['required', 'regex:'.Clan::TAG_PATTERN, 'unique:clans,clantag'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'clantag.regex' => __('2 to 4 capital letters or digits.'),
            'clantag.unique' => __('The tag :tag is taken.', ['tag' => $this->clantag]),
        ]);
    }

    private function draft(): ClanDraft
    {
        return new ClanDraft(
            trim($this->name),
            $this->clantag,
            trim($this->description) === '' ? null : trim($this->description),
            $this->picture,
            $this->meetupName,
            $this->meetupCity,
            $this->meetupUrl,
            $this->meetupLatitude,
            $this->meetupLongitude,
        );
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $meetups = $this->meetups;
    $current = $this->currentClan;
    $tag = $clantag !== '' ? $clantag : 'TAG';
    $proofRows = [
        ['kind', '32150 '.__('clan')],
        ['d', \Illuminate\Support\Str::slug($name) ?: '…'],
        ['name', $name ?: '…'],
        ['picture', $picture ?? __('none')],
        ['clantag', $clantag ?: '…'],
        ['p', \Illuminate\Support\Str::limit(auth()->user()->npub, 10, '…').', '.__('owner and captain')],
        [__('then'), __('membership kind 12150')],
    ];
@endphp

<div class="mx-auto flex w-full max-w-[1200px] grow flex-col gap-5 px-4 pb-6 lg:px-0 lg:pb-7">
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __('Start a clan') }}</h1>
        <span class="text-[13px] text-ink-2">{{ __('Name and tag are required. You can change everything else later.') }}</span>
    </div>

    <form wire:submit.prevent class="flex flex-col gap-[18px] rounded-lg bg-card p-4 lg:p-6"
          x-data="nostrAction({ pubkey: @js(auth()->user()->pubkey), messages: @js([
              'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
              'rejected' => __('The confirmation was not given. Please try again.'),
              'wrongKey' => __('This signer holds a different key than the one you logged in with.'),
              'failed' => __('That did not work. Please try again.'),
          ]) })">

        {{-- Portal meetup import (optional) --}}
        <div class="flex flex-col gap-2.5 rounded-md bg-ground p-4 shadow-ring">
            <label for="meetup" class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-[13px]"><b>{{ __('Start from your meetup') }}</b><span class="text-ink-3">{{ __('optional, fills in name, logo and text from the EINUNDZWANZIG portal') }}</span></label>
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
                <div class="flex flex-col gap-1.5">
                    <span class="relative block">
                        <x-icon name="search" :size="16" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
                        <input id="meetup" type="search" wire:model.live.debounce.400ms="meetupQuery" placeholder="{{ __('Search meetup or city') }}" autocomplete="off"
                               class="h-11 w-full rounded-lg border border-edge bg-ground pr-3.5 pl-10 text-[13px] text-ink placeholder:text-ink-3">
                    </span>
                    @if ($meetups === null)
                        <span class="text-xs text-loss" role="status">{{ __('The portal does not answer right now.') }}</span>
                    @elseif (mb_strlen(trim($meetupQuery)) >= 2)
                        <span class="text-xs text-ink-3">{{ trans_choice(':count match in the portal|:count matches in the portal', count($meetups)) }}@if ($meetupName), {{ __('picked: :name', ['name' => $meetupName]) }}@endif</span>
                        <ul class="m-0 flex list-none flex-col p-0">
                            @foreach ($meetups as $meetup)
                                <li wire:key="mu-{{ $meetup['id'] }}">
                                    <button type="button" wire:click="pickMeetup({{ $meetup['id'] }})" @class(['tr flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-sm px-2 text-left text-[13px]', 'text-btc' => $meetupId === $meetup['id']])>
                                        <span class="truncate">{{ $meetup['name'] }}</span><span class="shrink-0 text-xs text-ink-3">{{ $meetup['city'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if ($meetupName)
                    <div class="grid grid-cols-[72px_minmax(0,1fr)] gap-3.5 rounded-md bg-card p-3.5 shadow-[inset_0_0_0_1px_#3A3020]">
                        {{-- Logo slot: the imagery pass styles the portal logo. --}}
                        <span class="flex size-[72px] items-center justify-center overflow-hidden rounded-lg bg-[repeating-linear-gradient(135deg,#2A2016_0_6px,#1E1A12_6px_12px)] text-[11px] text-btc-hi">
                            @if ($picture)<img src="{{ $picture }}" alt="" class="size-full object-cover" loading="lazy">@else{{ __('logo') }}@endif
                        </span>
                        <span class="flex min-w-0 flex-col gap-1 text-[13px]">
                            <b class="truncate">{{ $meetupName }}</b>
                            <span class="text-ink-3">{{ __('Portal meetup, :city', ['city' => $meetupCity]) }}</span>
                            @if ($description !== '')<span class="line-clamp-2 leading-normal text-ink-2">{{ $description }}</span>@endif
                            <span class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-1.5 text-win"><x-icon name="check" :size="14" />{{ __('imported') }}</span>
                                <button type="button" wire:click="forgetMeetup" class="min-h-11 cursor-pointer text-xs text-ink-2 hover:text-ink">{{ __('Remove link') }}</button>
                            </span>
                        </span>
                    </div>
                @endif
            </div>
            <span class="text-xs text-ink-3">{{ __('Portal down? Create the clan anyway and fill in the fields yourself.') }}</span>
        </div>

        <div class="grid grid-cols-1 gap-7 lg:grid-cols-[minmax(0,1fr)_260px]">
            <div class="flex flex-col gap-[18px]">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                    <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Name') }}</span>
                        <input wire:model.blur="name" required maxlength="64" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink">
                        @error('name')<span class="text-xs text-loss">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Tag, 2 to 4 characters') }}</span>
                        <input wire:model.live.debounce.300ms="clantag" required maxlength="4" aria-describedby="tag-hint" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] font-bold text-ink uppercase">
                    </label>
                </div>
                <div id="tag-hint" class="-mt-2.5 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 text-xs">
                    <span class="text-ink-2">{{ __('Your tag shows on ladders, rankings and match cards.') }}</span>
                    @switch($this->tagStatus)
                        @case('free')<span class="flex items-center gap-1.5 text-win"><x-icon name="check" :size="14" />{{ __(':tag is free', ['tag' => $clantag]) }}</span>@break
                        @case('taken')<span class="flex items-center gap-1.5 text-loss">{{ __(':tag is taken', ['tag' => $clantag]) }}</span>@break
                        @case('invalid')<span class="text-loss">{{ __('2 to 4 capital letters or digits.') }}</span>@break
                        @default<span></span>
                    @endswitch
                    <span class="col-span-full text-ink-3">{{ __('Tags are unique across all games. First come, first served.') }}</span>
                    @error('clantag')<span class="col-span-full text-loss">{{ $message }}</span>@enderror
                </div>
                <label class="flex flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Description, public') }}</span>
                    <textarea wire:model.blur="description" rows="3" maxlength="1000" class="h-24 w-full resize-y rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] leading-normal text-ink"></textarea>
                    @error('description')<span class="text-xs text-loss">{{ $message }}</span>@enderror
                </label>
            </div>
            <div class="flex flex-col gap-2">
                <span class="text-xs text-ink-2">{{ __('logo') }}</span>
                {{-- Logo slot: own uploads come with the imagery pass. --}}
                <span class="flex size-[132px] items-center justify-center overflow-hidden rounded-lg bg-[repeating-linear-gradient(135deg,#2A2016_0_8px,#1E1A12_8px_16px)] text-xs text-btc-hi">
                    @if ($picture)<img src="{{ $picture }}" alt="" class="size-full object-cover" loading="lazy">@else{{ __('no logo yet') }}@endif
                </span>
                <div class="mt-1 flex flex-col gap-2">
                    <span class="text-xs text-ink-2">{{ $picture ? __('Logo, taken from your meetup') : __('Logo, from your meetup') }}</span>
                    <button type="button" disabled title="{{ __('Uploads come with the next update.') }}" class="flex h-11 cursor-not-allowed items-center justify-center rounded-lg border border-edge bg-well px-3.5 text-[13px] text-ink-3">{{ __('Upload image') }}</button>
                </div>
            </div>
        </div>

        @if ($current)
            <div class="flex gap-3 rounded-md bg-btc-chip px-4 py-3.5 text-[13px] leading-normal shadow-[inset_0_0_0_1px_#5A4520]">
                <x-icon name="alert" :size="16" class="mt-0.5 text-btc-hi" />
                <span>
                    <b>{{ $current->memberOf(auth()->user())?->role === ClanRole::Captain ? __('You are captain of :clan.', ['clan' => $current->name]) : __('You play for :clan.', ['clan' => $current->name]) }}</b>
                    {{ __('A player belongs to one clan. Start this one and you leave :clan and its lineups.', ['clan' => $current->name]) }}
                    @if ($current->owner_id === auth()->id() && $current->members->count() > 1){{ __(':clan then needs a new captain.', ['clan' => $current->name]) }}@endif
                </span>
            </div>
        @endif

        @error('clan')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
        <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>

        <div class="flex flex-wrap items-center gap-5">
            <button type="button" x-on:click="run('prepareCreate', 'create')" x-bind:disabled="busy"
                    class="btn-p inline-flex h-[52px] cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-7 text-[15px] font-bold whitespace-nowrap text-on-btc disabled:cursor-wait disabled:opacity-70">
                <x-icon name="shield-check" :size="18" />{{ __('Start clan') }}
            </button>
            <span class="text-xs leading-normal text-ink-2">{{ __('We save the clan and make you its captain in one step.') }}</span>
        </div>
    </form>

    <section aria-label="{{ __('Preview') }}" class="grid grid-cols-1 gap-7 rounded-lg bg-card px-4 py-5 lg:grid-cols-2 lg:px-6">
        <div class="flex flex-col gap-3">
            <span class="flex items-baseline justify-between"><h2 class="m-0 text-[15px] font-bold">{{ __('How your clan shows up') }}</h2><span class="text-xs text-ink-3">{{ __('clan list') }}</span></span>
            <div class="grid grid-cols-[44px_minmax(0,1fr)_auto] items-center gap-3.5 rounded-md bg-ground p-3.5 shadow-ring">
                <span class="flex size-11 items-center justify-center rounded-md bg-btc-tint text-xs font-bold text-btc">{{ $tag }}</span>
                <span class="flex min-w-0 flex-col gap-1"><b class="truncate text-sm">{{ $name ?: __('Your clan') }}</b><span class="text-xs text-ink-3">{{ __('1 player, no lineups yet') }}</span></span>
                <span class="flex flex-col items-end gap-1"><b class="font-display text-base">–</b><span class="hidden text-xs text-ink-3 sm:inline">{{ __('Clan Rating, needs 3 rated players') }}</span></span>
            </div>
            <span class="text-xs leading-normal text-ink-3">{{ __('Once created, your clan link to share shows up here.') }}</span>
        </div>
        <div class="flex flex-col">
            <h2 class="m-0 pb-1.5 text-[15px] font-bold">{{ __('Your clan at the start') }}</h2>
            @foreach ([[__('Captain'), auth()->user()->displayName()], [__('Clan Rating'), __('average of your top 3 players, once 3 have a rating')], [__('Hashrate'), __('0, grows with every rated game a player plays')], [__('Lineups'), __('add them for Rocket League after creating')]] as [$key, $value])
                <div class="grid min-h-9 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1 text-[13px] lg:grid-cols-[140px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
            <x-proof :rows="$proofRows" class="mt-3" />
        </div>
    </section>
</div>
