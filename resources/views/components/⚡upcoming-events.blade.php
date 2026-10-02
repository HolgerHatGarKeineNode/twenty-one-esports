<?php

use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\UpcomingEvents;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A player's upcoming events (2026-10-02, App\Support\Dock\UpcomingEvents):
 * open match rooms and registered tournaments, most urgent first.
 *
 * - `card`: home, at the very top: the most urgent one big (cover, who,
 *   countdown, one large button), the rest behind "+N more";
 * - `list`: the top of /matches (rooms), /tournaments (tournaments) and a
 *   game page (that game's), one row each.
 *
 * Nothing at all without an event, or for a guest. The numbers count down in
 * the browser (resources/js/upcomingEvents.js) from the server's timestamps;
 * when one reaches zero (a check-in opens, a match starts) the component
 * renders again, so the phase and its words follow without polling.
 */
new class extends Component {
    /** Rows of the list before "+N more". */
    public const LIST_ROWS = 4;

    #[Locked]
    public string $variant = 'list';

    /** `series` (rooms) or `tournament`; null for both. */
    #[Locked]
    public ?string $only = null;

    /** A game slug: only that game's events. */
    #[Locked]
    public ?string $game = null;

    /** Classes of the list's frame (its place on the page), rendered only when there is something to show. */
    #[Locked]
    public string $frame = '';

    /**
     * @return Collection<int, DockItem>
     */
    #[Computed]
    public function items(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return app(UpcomingEvents::class)->for($user)
            ->filter(fn (DockItem $item): bool => ($this->only === null || $item->kind === $this->only) && ($this->game === null || $item->game() === $this->game))
            ->values();
    }
}; ?>

@php
    $items = $this->items;
    $nowMs = (int) now()->getTimestampMs();
    $config = ['now' => $nowMs, 'labels' => ['hm' => __(':h h :m'), 'min' => __(':m min')]];
    // The words around a running number: "in 3 h 20" before something starts, "4:10 left" for a step.
    $suffix = fn (DockItem $item): string => $item->countsToStart() ? __('in :left') : __(':left left');
    $button = fn (DockItem $item): string => match (true) {
        $item->kind === 'tournament' => __('Open tournament'),
        $item->phase === 'checkin' && $item->needsYou => __('Check in now'),
        default => __('Open room'),
    };
@endphp

{{-- `contents`: with nothing to show the component takes no room, not even a flex gap of its page. --}}
<div class="contents" x-data="upcomingEvents(@js($config))">
    @if ($items->isNotEmpty())
        @if ($variant === 'card')
            @php
                $first = $items->first();
                $rest = $items->slice(1);
                $heading = $first->kind === 'tournament' ? __('Your next event') : __('Your next match');
            @endphp
            <section aria-labelledby="upcoming-h" class="px-4 pt-4 lg:px-12 lg:pt-6" data-test="upcoming-card" x-data="{ more: false }">
                <div class="flex flex-col gap-3 rounded-card bg-card p-3 shadow-ring-btc sm:p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="upcoming-h" class="m-0 text-[13px] font-bold text-btc">{{ $heading }}</h2>
                        @if ($rest->isNotEmpty())
                            <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center gap-1 rounded-md px-2 text-[13px] text-ink-2 hover:text-ink" x-on:click="more = ! more"
                                    x-bind:aria-expanded="more.toString()" aria-expanded="false" aria-controls="upcoming-more-list" data-test="upcoming-more">
                                {{ __('+:count more', ['count' => $rest->count()]) }}
                                <x-icon name="chevron-down" :size="16" x-show="! more" />
                                <x-icon name="chevron-up" :size="16" x-show="more" x-cloak />
                            </button>
                        @endif
                    </div>

                    {{-- From lg the button sits at the end of the row, beside who and when. --}}
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-6">
                    <div class="flex min-w-0 items-center gap-3 lg:grow" data-key="{{ $first->key }}">
                        <x-game-cover :game="(string) $first->game()" size="thumb" loading="eager" class="w-24 shrink-0 rounded-tag sm:w-40" />
                        <div class="flex min-w-0 grow flex-col gap-1">
                            <span class="truncate text-xs text-ink-2">{{ $first->title }} · {{ \App\Support\GameNames::game((string) $first->game()) }}@if ($first->number !== '') · {{ $first->number }}@endif</span>
                            <span class="flex min-w-0 items-center gap-2">
                                @if ($first->face)
                                    <x-avatar :user="$first->face" :size="24" class="shrink-0 rounded-tag" />
                                @endif
                                <b class="truncate text-base leading-5 sm:text-lg" data-test="upcoming-name">{{ $first->name }}</b>
                            </span>
                            <x-upcoming.when :item="$first" :now-ms="$nowMs" :suffix="$suffix($first)" class="text-[13px]" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center lg:shrink-0 lg:flex-row-reverse">
                        <a href="{{ $first->href }}" class="btn-p inline-flex h-12 items-center justify-center gap-2 rounded-md bg-btc px-6 text-sm font-bold text-on-btc hover:text-on-btc sm:min-w-56" data-test="upcoming-open">
                            {{ $button($first) }}<x-icon name="next" :size="18" />
                        </a>
                        @if ($first->withdraw)
                            <a href="{{ $first->withdraw }}" class="inline-flex min-h-11 items-center justify-center px-2 text-[13px] text-ink-2 underline-offset-2 hover:text-ink hover:underline" data-test="upcoming-withdraw">{{ __('Can’t make it? Pull out') }}</a>
                        @endif
                    </div>
                    </div>

                    @if ($rest->isNotEmpty())
                        <ul id="upcoming-more-list" class="m-0 flex list-none flex-col gap-1 border-t border-hairline p-0 pt-2 lg:grid lg:grid-cols-2" x-show="more" x-cloak>
                            @foreach ($rest as $item)
                                <x-upcoming.row :item="$item" :now-ms="$nowMs" :suffix="$suffix($item)" />
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @else
            @php
                $shown = $items->take($this::LIST_ROWS);
                $hidden = $items->slice($this::LIST_ROWS);
                $heading = match ($only) {
                    'series' => __('Your open match rooms'),
                    'tournament' => __('Your tournaments'),
                    default => __('Your upcoming matches and events'),
                };
            @endphp
            <div class="{{ $frame }}">
            <section aria-labelledby="upcoming-list-h" class="flex flex-col gap-1 rounded-card bg-card p-2 shadow-ring-btc" data-test="upcoming-list" x-data="{ more: false }">
                <h2 id="upcoming-list-h" class="m-0 px-2 pt-1 text-[13px] font-bold text-btc">{{ $heading }}</h2>
                <ul class="m-0 flex list-none flex-col gap-1 p-0">
                    @foreach ($shown as $item)
                        <x-upcoming.row :item="$item" :now-ms="$nowMs" :suffix="$suffix($item)" />
                    @endforeach
                    @foreach ($hidden as $item)
                        <x-upcoming.row :item="$item" :now-ms="$nowMs" :suffix="$suffix($item)" x-show="more" x-cloak />
                    @endforeach
                </ul>
                @if ($hidden->isNotEmpty())
                    <button type="button" class="inline-flex h-11 cursor-pointer items-center gap-1 self-start rounded-md px-2 text-[13px] text-ink-2 hover:text-ink" x-on:click="more = ! more"
                            x-bind:aria-expanded="more.toString()" aria-expanded="false" x-show="! more" data-test="upcoming-more">{{ __('+:count more', ['count' => $hidden->count()]) }}</button>
                @endif
            </section>
            </div>
        @endif
    @endif
</div>
