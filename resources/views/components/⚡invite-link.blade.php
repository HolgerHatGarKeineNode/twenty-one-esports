<?php

use App\Enums\InviteLinkType;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/*
 * "Invite a friend by link" (P6b), one module for every page that asks a
 * player to bring a friend: the chess lobby, the series game pages, the
 * player's own page, their clan page, an empty ladder and a finished game.
 *
 * What the link opens follows the page's game:
 *  - chess: a daily chess link anyone can take (InviteLinks, type Daily);
 *  - a series game: the join link of the clan this player captains (a series
 *    is played by clan lineups); a player without a clan is sent to start
 *    one, a player of a clan they don't captain is told who makes the links.
 * `clan` pins the module to one clan (the clan page): it renders nothing
 * unless the viewer captains that clan.
 *
 * Compact: one row with the heading and the button, the options (who can use
 * it, how long it works, the colour) open inline. Full (the challenge page):
 * options open and the long explanation shown. A guest sees the login state.
 * Making the link opens its landing page, where it is copied and shared.
 * Invite links are casual and allowed before Block 0: nothing to gate here.
 */
new class extends Component
{
    #[Locked]
    public string $game = 'chess';

    #[Locked]
    public ?int $clanId = null;

    #[Locked]
    public bool $compact = true;

    /** Where the module sits (data-test and the heading id stay unique per page). */
    #[Locked]
    public string $place = 'page';

    /** The colour picked by the page around the module (the challenge page); null shows the module's own choice. */
    #[Reactive]
    public ?string $color = null;

    public string $uses = 'once';

    public int $hours = 48;

    public string $linkColor = 'random';

    public string $error = '';

    public function mount(string $game = 'chess', ?int $clanId = null, bool $compact = true, string $place = 'page', ?string $color = null): void
    {
        $this->game = $game;
        $this->clanId = $clanId;
        $this->compact = $compact;
        $this->place = $place;
        $this->color = $color;

        $type = $this->type();

        if ($type !== null) {
            $this->uses = $type === InviteLinkType::Clan ? 'several' : 'once';
            $this->hours = $type->defaultExpiryHours();
        }
    }

    /**
     * The viewer's state: guest | daily | clan | no-clan | member.
     */
    #[Computed]
    public function state(): string
    {
        $user = Auth::user();

        // A board game gets its own invite links in P5 of plan "Mühle und Dame", never a chess daily link.
        // A score game (plan "AoE2 und Trackmania", P4) has nobody to invite to a match.
        if (app(GameRegistry::class)->isBoard($this->game) || app(GameRegistry::class)->isScore($this->game)) {
            return 'hidden';
        }

        if (! $user instanceof User) {
            return $this->clanId !== null ? 'hidden' : 'guest';
        }

        if ($this->clanId !== null) {
            return $this->clan?->isCaptain($user) ? 'clan' : 'hidden';
        }

        if (! app(GameRegistry::class)->isSeries($this->game)) {
            return 'daily';
        }

        return match (true) {
            $this->clan === null => 'no-clan',
            $this->clan->isCaptain($user) => 'clan',
            default => 'member',
        };
    }

    /**
     * The clan the link is for: the pinned one, else the viewer's own.
     */
    #[Computed]
    public function clan(): ?Clan
    {
        if ($this->clanId !== null) {
            return Clan::query()->with('members')->find($this->clanId);
        }

        $user = Auth::user();

        return $user instanceof User ? $user->clanMember?->clan()->with('members')->first() : null;
    }

    public function createLink(InviteLinks $links): void
    {
        $this->error = '';
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $type = $this->type();

        if ($type === null) {
            abort(403);
        }

        $options = ['uses' => $this->uses, 'hours' => $this->hours];

        if ($type === InviteLinkType::Daily) {
            $options['color'] = $this->color ?? $this->linkColor;
        } else {
            $options['clan'] = $this->clan;
        }

        try {
            $link = $links->create($user, $type, $options);
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->redirectRoute('invites.link', $link);
    }

    private function type(): ?InviteLinkType
    {
        return match ($this->state) {
            'daily' => InviteLinkType::Daily,
            'clan' => InviteLinkType::Clan,
            default => null,
        };
    }
}; ?>

@php
    $state = $this->state;
    $type = match ($state) { 'daily' => InviteLinkType::Daily, 'clan' => InviteLinkType::Clan, default => null };
    $clan = in_array($state, ['clan', 'member'], true) ? $this->clan : null;
    $id = 'invite-'.$place;
    $heading = match ($state) {
        'clan' => __('Invite a friend to :clan', ['clan' => $clan?->name]),
        default => __('Invite a friend by link'),
    };
    $text = match ($state) {
        'guest' => __('Log in, make a link and send it on Signal, Telegram or Nostr. Your friend lands right in the game.'),
        'daily' => __('A daily chess game with whoever opens the link and accepts. Casual, so it never counts toward ratings or rewards.'),
        'clan' => __('Everyone who uses the link sends a join request; a captain confirms each one.'),
        'no-clan' => __('Series are played by clan lineups. Start a clan, then invite your friends by link.'),
        'member' => __('Join links for :clan come from its captains. Ask one of them, or challenge a friend to daily chess.', ['clan' => $clan?->name]),
        default => '',
    };
    $colorLabel = ['random' => __('Random'), 'white' => __('White'), 'black' => __('Black')];
    // A game page (P26) keeps the module to one row below sm in every state: heading and button, the text from sm.
    $slim = ($type !== null && $compact) || $place === 'game';
@endphp

{{-- A hidden module keeps its root (Livewire needs one) but takes no place, not even a flex gap. --}}
<div @if ($state === 'hidden') hidden @endif>
@if ($state !== 'hidden')
    <section aria-labelledby="{{ $id }}-h" x-data="{ open: @js(! $compact) }"
             class="@container flex flex-col gap-4 rounded-lg bg-card px-4 py-4 shadow-ring-btc lg:px-6"
             data-test="invite-module" data-place="{{ $place }}" data-state="{{ $state }}">
        {{-- On a game page (P56) the module sits in a side column from lg: its own width decides the row, not the window's. --}}
        <div @class(['flex flex-col gap-3', 'sm:flex-row sm:items-center sm:gap-6' => $place !== 'game', '@xl:flex-row @xl:items-center @xl:gap-6' => $place === 'game'])>
            {{-- The icon centres on a lone heading (compact, below sm) and tops a heading with its text. --}}
            <span @class(['flex min-w-0 grow gap-3', 'items-center sm:items-start' => $slim && $place !== 'game', 'items-center @xl:items-start' => $place === 'game', 'items-start' => ! $slim])>
                <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-btc-tint text-btc" aria-hidden="true"><x-icon name="link" :size="20" /></span>
                <span class="flex min-w-0 flex-col gap-1">
                    <h2 id="{{ $id }}-h" class="m-0 font-display text-base leading-[1.25] font-bold break-words">{{ $heading }}</h2>
                    {{-- Below sm the row stays one heading and one button; a link-making player reads this in the options. --}}
                    <span @class(['max-w-[68ch] text-[13px] leading-normal text-ink-2', 'max-sm:hidden' => $slim && $place !== 'game', '@max-xl:hidden' => $place === 'game'])>{{ $text }}</span>
                </span>
            </span>

            <span class="flex shrink-0 flex-wrap items-center gap-2">
                @switch ($state)
                    @case('guest')
                        <a href="{{ route('login') }}" data-test="invite-login"
                           class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi hover:bg-btc-press hover:text-btc-hi">{{ __('Log in to invite') }}</a>
                        @break
                    @case('no-clan')
                        <a href="{{ route('clans.create') }}" data-test="invite-start-clan"
                           class="inline-flex h-11 items-center justify-center rounded-md border border-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi hover:bg-btc-press hover:text-btc-hi">{{ __('Start a clan') }}</a>
                        <a href="{{ route('clans.index') }}" class="inline-flex h-11 items-center px-2 text-[13px] whitespace-nowrap">{{ __('Join a clan') }}</a>
                        @break
                    @case('member')
                        <a href="{{ route('chess.challenge') }}#invite-by-link" data-test="invite-daily-instead"
                           class="inline-flex h-11 items-center justify-center rounded-md border border-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi hover:bg-btc-press hover:text-btc-hi">{{ __('Invite to daily chess') }}</a>
                        @break
                    @default
                        <button type="button" wire:click="createLink" wire:loading.attr="disabled" data-test="invite-create"
                                class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-btc bg-transparent px-[18px] max-sm:grow text-[13px] font-bold whitespace-nowrap text-btc-hi hover:bg-btc-press disabled:opacity-70">
                            <x-icon name="link" :size="16" />{{ $state === 'clan' ? __('Create join link') : __('Create invite link') }}
                        </button>
                        @if ($compact)
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="{{ $id }}-options" data-test="invite-options-toggle"
                                    class="inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md border-0 bg-transparent px-2 text-[13px] text-ink-2 hover:text-ink">
                                {{ __('Options') }}<x-icon name="chevron-down" :size="14" class="transition-transform duration-150 motion-reduce:transition-none" ::class="open ? 'rotate-180' : ''" />
                            </button>
                        @endif
                @endswitch
            </span>
        </div>

        @if ($type !== null)
            <div id="{{ $id }}-options" x-show="open" @if ($compact) x-cloak @endif
                 x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 -translate-y-1"
                 class="grid grid-cols-1 gap-4 border-t border-hairline pt-4 sm:grid-cols-2 lg:grid-cols-3" data-test="invite-options">
                @if ($compact)<p class="m-0 text-[13px] leading-normal text-ink-2 sm:hidden">{{ $text }}</p>@endif
                <div class="flex flex-col gap-2">
                    <span id="{{ $id }}-uses" class="text-xs text-ink-2">{{ __('Who can use it') }}</span>
                    <div role="radiogroup" aria-labelledby="{{ $id }}-uses" class="grid grid-cols-2 gap-2">
                        @foreach (['once' => $type === InviteLinkType::Clan ? __('One player') : __('Once'), 'several' => __('Several times')] as $value => $label)
                            <button type="button" role="radio" wire:click="$set('uses', '{{ $value }}')" aria-checked="{{ $uses === $value ? 'true' : 'false' }}" data-test="invite-uses-{{ $value }}"
                                    @class(['h-11 cursor-pointer rounded-md border bg-ground px-2 text-[13px] text-ink', 'border-btc' => $uses === $value, 'border-line' => $uses !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <label class="flex flex-col gap-2">
                    <span class="text-xs text-ink-2">{{ __('Link works for') }}</span>
                    <select wire:model.number="hours" data-test="invite-hours" class="h-11 w-full rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                        @foreach ($type->expiryChoices() as $choice)
                            <option value="{{ $choice }}">{{ $type === InviteLinkType::Clan || $choice >= 168 ? trans_choice(':count day|:count days', intdiv($choice, 24)) : trans_choice(':count hour|:count hours', $choice) }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($type === InviteLinkType::Daily && $color === null)
                    <div class="flex flex-col gap-2 sm:col-span-2 lg:col-span-1">
                        <span id="{{ $id }}-color" class="text-xs text-ink-2">{{ __('Your color') }}</span>
                        <div role="radiogroup" aria-labelledby="{{ $id }}-color" class="grid grid-cols-3 gap-2">
                            @foreach ($colorLabel as $value => $label)
                                <button type="button" role="radio" wire:click="$set('linkColor', '{{ $value }}')" aria-checked="{{ $linkColor === $value ? 'true' : 'false' }}" data-test="invite-color-{{ $value }}"
                                        @class(['h-11 cursor-pointer rounded-md border bg-ground px-2 text-[13px] text-ink', 'border-btc' => $linkColor === $value, 'border-line' => $linkColor !== $value])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <p class="m-0 text-xs leading-normal text-ink-2 sm:col-span-2 lg:col-span-3">
                    @if ($type === InviteLinkType::Clan)
                        {{ $uses === 'once' ? __('One player: the first request closes the link.') : __('Several players, one request each.') }}
                    @else
                        {{ $uses === 'once' ? __('Once: the first friend who accepts plays, then the link closes.') : __('Several times: every friend who accepts gets their own game with you.') }}
                    @endif
                </p>
            </div>
        @endif

        @if ($error)<p class="m-0 text-[13px] text-loss" role="alert" data-test="invite-error">{{ $error }}</p>@endif
    </section>
@endif
</div>
