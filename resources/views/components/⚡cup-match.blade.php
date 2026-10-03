<?php

use App\Models\User;
use App\Support\Tournaments\CupMatchNow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A player's open cup or tournament match, wherever they are (user,
 * 2026-10-03: "die Auffindbarkeit der Turniermatches unbedingt
 * perfektionieren! ÜBERALL!!!!"). What it is and where it leads is
 * App\Support\Tournaments\CupMatchNow.
 *
 * - `badge`: the header, next to the bell, on every page: a pill that
 *   pulses while the game is live;
 * - `banner`: the top of home, the chess lobby, the chess page and
 *   /matches: what is on, against whom, one big button, and, in a running
 *   round, that casual games wait until it is done.
 *
 * Nothing for a guest or a player without an open match. It renders again
 * when a notification, a started game or a changed series arrives
 * (resources/js/cupMatch.js), so "waiting" turns to "live" on its own.
 */
new class extends Component {
    #[Locked]
    public string $variant = 'badge';

    /** Classes of the banner's frame (its place on the page), rendered only when there is something to show. */
    #[Locked]
    public string $frame = '';

    /**
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    #[Computed]
    public function cup(): ?array
    {
        $user = auth()->user();

        return $user instanceof User ? app(CupMatchNow::class)->for($user) : null;
    }
}; ?>

@php
    $cup = $this->cup;
    $config = [
        'userId' => auth()->id(),
        'poll' => (int) config('esports.dock.poll_seconds'),
        'pollWithSocket' => (int) config('esports.dock.poll_seconds_with_socket'),
    ];
@endphp

{{-- `contents`: with nothing to show the component takes no room, not even a flex gap of its place. --}}
<div class="contents" x-data="cupMatch(@js($config))">
    @if ($cup && $variant === 'badge')
        <a href="{{ $cup['href'] }}" title="{{ $cup['label'] }} · {{ $cup['tournament'] }}"
           @class([
               'relative flex h-11 shrink-0 items-center gap-1.5 rounded-md px-2.5 text-[13px] font-bold whitespace-nowrap',
               'bg-btc text-on-btc hover:text-on-btc' => $cup['state'] === 'live',
               'bg-btc-chip text-btc-hi shadow-[inset_0_0_0_1px_var(--color-btc)] hover:text-btc-hi' => $cup['state'] !== 'live',
           ])
           data-test="cup-badge" data-state="{{ $cup['state'] }}" wire:key="cup-badge-{{ $cup['key'] }}">
            <x-icon name="trophy" :size="18" />
            <span class="max-sm:sr-only">{{ $cup['short'] }}</span>
            <span class="sr-only">: {{ $cup['label'] }}, {{ $cup['tournament'] }}</span>
            @if ($cup['state'] === 'live')
                {{-- The pulse says "now"; the colour is not the only sign: the word and the icon say it too. --}}
                <span aria-hidden="true" class="absolute -top-1 -right-1 flex size-3.5 items-center justify-center rounded-full bg-ground"><span class="size-2.5 animate-live rounded-full bg-live"></span></span>
            @endif
        </a>
    @elseif ($cup && $variant === 'banner')
        <section aria-labelledby="cup-match-h" class="{{ $frame }}" data-test="cup-banner" data-state="{{ $cup['state'] }}" wire:key="cup-banner-{{ $cup['key'] }}">
            <div @class([
                'flex flex-col gap-3 rounded-lg px-4 py-3 sm:flex-row sm:items-center sm:gap-5 lg:px-6 lg:py-4',
                'bg-btc-tint shadow-[inset_0_0_0_2px_var(--color-btc)]',
            ])>
                <span @class(['relative flex size-11 shrink-0 items-center justify-center rounded-lg bg-btc text-on-btc max-sm:hidden']) aria-hidden="true">
                    <x-icon name="trophy" :size="24" />
                    @if ($cup['state'] === 'live')
                        <span class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-ground"><span class="size-2.5 animate-live rounded-full bg-live"></span></span>
                    @endif
                </span>
                <span class="flex min-w-0 grow flex-col gap-0.5">
                    <h2 id="cup-match-h" class="m-0 flex items-center gap-2 font-display text-lg leading-tight font-extrabold lg:text-xl">
                        <x-icon name="trophy" :size="18" class="shrink-0 text-btc sm:hidden" />
                        <span>{{ match ($cup['state']) {
                            'live' => $cup['cup'] ? __('You have a cup game live') : __('You have a tournament game live'),
                            'lobby' => $cup['cup'] ? __('Your cup match is on') : __('Your tournament match is on'),
                            default => $cup['cup'] ? __('Your cup match is next') : __('Your tournament match is next'),
                        } }}</span>
                    </h2>
                    <span class="text-[13px] text-ink [overflow-wrap:anywhere]" data-test="cup-banner-line">{{ $cup['tournament'] }} · {{ $cup['round'] }}@if ($cup['opponent'] !== '') · {{ __('against :name', ['name' => $cup['opponent']]) }}@endif</span>
                    @if ($cup['locked'])
                        <span class="text-xs text-ink-2" data-test="cup-banner-lock">{{ __('Your cup match comes first: casual games wait until it is done.') }}</span>
                    @endif
                </span>
                <x-button :href="$cup['href']" icon="next" class="h-12 shrink-0 px-6 text-sm sm:min-w-48" data-test="cup-banner-open">{{ match ($cup['state']) {
                    'live' => __('Play now'),
                    'lobby' => __('Join your lobby'),
                    default => __('Open tournament'),
                } }}</x-button>
            </div>
        </section>
    @endif
</div>
