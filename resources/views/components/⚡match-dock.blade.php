<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The match dock (P5f, MatchDock.dc.html from 1024 px, MobileMatchDock.dc.html
 * below): one tab per open match, floating at the bottom right of every page
 * of a logged-in player. What counts as open, and in which order, is
 * App\Support\Dock\OpenMatches.
 *
 * Tabs render on the server. The panels live in the `panel` island, skipped
 * on the first render and loaded when a tab is first opened; a refresh of the
 * dock leaves them alone. Live updates come from resources/js/matchDock.js:
 * the player's private channel and the watch channel of each chess game on
 * the dock ask for `$refresh`, without a websocket a poll does.
 *
 * The page the player is on is read once, at mount: its game or series never
 * has a tab, and on a game page the dock moves out of the bottom edge into a
 * button in the title row (from lg) or onto the page's own bottom bar.
 */
new class extends Component {
    /** Tabs that fit next to the handle: three from lg, four from xl. */
    public const TABS_LG = 3;

    public const TABS_XL = 4;

    #[Locked]
    public ?int $excludeGame = null;

    #[Locked]
    public ?int $excludeSeries = null;

    /** On a running game page: the dock goes into the title row from lg. */
    #[Locked]
    public bool $gamePage = false;

    /** On the player's own live blitz board: no ring, no pop-up. */
    #[Locked]
    public bool $quiet = false;

    public function mount(): void
    {
        ['game' => $this->excludeGame, 'series' => $this->excludeSeries] = OpenMatches::onScreen(request());

        if ($this->excludeGame !== null) {
            $game = ChessGame::query()->find($this->excludeGame);
            $this->gamePage = $game?->isActive() === true;
            $this->quiet = $this->gamePage && ! $game->isCorrespondence() && $game->colorOf($this->user()) !== null;
        }
    }

    /**
     * @return Collection<int, DockItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return app(OpenMatches::class)->for($this->user(), $this->excludeGame, $this->excludeSeries);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $items = $this->items;
    $counts = OpenMatches::count($items);
    $nowMs = (int) now()->getTimestampMs();
    $viewer = auth()->user();
    $first = $items->first(fn ($item) => $item->needsYou) ?? $items->first();
    $hiddenLg = $items->slice($this::TABS_LG);
    $hiddenXl = $items->slice($this::TABS_XL);
    $needLabel = $counts['need'] > 0 ? trans_choice('need you|need you', $counts['need']) : __('waiting');
    $handleNumber = $counts['need'] > 0 ? $counts['need'] : $counts['wait'];
    $summary = __(':need need you, :open open', ['need' => $counts['need'], 'open' => $counts['open']]);
    $config = [
        'now' => $nowMs,
        'need' => $counts['need'],
        'poll' => (int) config('esports.dock.poll_seconds'),
        'pollWithSocket' => (int) config('esports.dock.poll_seconds_with_socket'),
        'quiet' => $quiet,
        'labels' => ['hm' => __(':h h :m'), 'min' => __(':m min'), 'turned' => __('Your move: :sentence')],
    ];
@endphp

<div x-data="matchDock(@js($config))" data-need="{{ $counts['need'] }}" data-open="{{ $counts['open'] }}" data-test="match-dock-root"
     x-on:keydown.escape.window="close(true)" x-on:bell-toggle.window="$event.detail && close(false)" x-on:chat-sheet-toggle.window="chatOpen = $event.detail; $event.detail && close(false)">
    <span class="sr-only" aria-live="polite" x-text="announcement"></span>

    @if ($items->isNotEmpty())
        {{--
            Every item once, for resources/js/matchDock.js (which turned to "on you", which chess
            games to watch) and alerts.js (no toast for a game that already has a tab).
        --}}
        @foreach ($items as $item)
            <span hidden wire:key="item-{{ $item->key }}" data-dock-item="{{ $item->key }}" data-need="{{ $item->needsYou ? '1' : '0' }}" data-sentence="{{ $item->sentence }}"
                  @if ($item->gameId()) data-dock-game="{{ $item->gameId() }}" @endif></span>
        @endforeach

        {{-- Room below the footer while the dock floats, so the page end never sits under it. --}}
        <div aria-hidden="true" @class(['h-[88px]', 'lg:hidden' => $gamePage]) x-show="! pageBar" data-test="dock-spacer"></div>

        {{-- Desktop bar (MatchDock.dc.html) --}}
        @unless ($gamePage)
            <section aria-label="{{ __('Your open matches') }}" class="fixed right-6 bottom-4 z-[35] hidden lg:block" data-test="match-dock"
                     x-ref="bar" x-on:mouseenter="inside = true" x-on:mouseleave="inside = false; flush()" x-on:focusin="inside = true" x-on:focusout="leaveFocus($event)"
                     x-on:click.outside="open !== 'slot' && close(false)" x-on:keydown.left="step(-1, $event)" x-on:keydown.right="step(1, $event)">
                <div class="absolute bottom-16" x-bind:style="{ left: panelLeft + 'px' }">
                    @island(name: 'panel', skip: true)
                        @php($nowMs = (int) now()->getTimestampMs())
                        @php($viewer = auth()->user())
                        @placeholder
                            <div class="dk-panel dk-rise" x-show="open && open.startsWith('item:')" x-cloak role="status" data-test="dock-panel-loading">
                                <div class="flex h-[52px] items-center border-b border-hairline px-4 text-[13px] text-ink-2">{{ __('Loading…') }}</div>
                                <div class="flex flex-col gap-3 p-4"><span class="sk h-10 rounded-md"></span><span class="sk h-36 rounded-md"></span><span class="sk h-11 rounded-md"></span></div>
                            </div>
                        @endplaceholder
                        @foreach ($this->items as $item)
                            <x-match-dock.panel :item="$item" :now-ms="$nowMs" :viewer="$viewer" />
                        @endforeach
                        <div class="dk-panel dk-rise" x-show="open && open.startsWith('item:') && ! $root.querySelector('[data-panel=&quot;' + open.slice(5) + '&quot;]')" x-cloak role="status">
                            <div class="flex h-[52px] items-center border-b border-hairline px-4 text-[13px] text-ink-2">{{ __('Loading…') }}</div>
                        </div>
                    @endisland
                </div>

                @if ($hiddenLg->isNotEmpty())
                    <div id="dock-more" role="region" aria-label="{{ __('More open matches') }}" class="dk-panel dk-rise absolute right-0 bottom-16 w-[320px]" x-show="open === 'more'" x-cloak data-test="dock-more">
                        <div class="flex h-[52px] items-center gap-2 border-b border-hairline pr-1 pl-4">
                            <b class="text-[13px] xl:hidden">{{ trans_choice(':count more, :need needs you|:count more, :need need you', $hiddenLg->count(), ['need' => $hiddenLg->where('needsYou', true)->count()]) }}</b>
                            <b class="hidden text-[13px] xl:inline">{{ trans_choice(':count more, :need needs you|:count more, :need need you', $hiddenXl->count(), ['need' => $hiddenXl->where('needsYou', true)->count()]) }}</b>
                            <span class="grow"></span>
                            <button type="button" class="dk-x" x-on:click="close(true)" aria-label="{{ __('Close list') }}"><x-icon name="close" :size="16" /></button>
                        </div>
                        <div class="flex max-h-[min(420px,calc(100svh-160px))] flex-col overflow-y-auto p-1">
                            @foreach ($hiddenLg as $index => $item)
                                <x-match-dock.row :item="$item" :now-ms="$nowMs" :class="$index < $this::TABS_XL ? 'xl:hidden' : ''" />
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex gap-1 rounded-lg bg-card p-1 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]">
                    <button type="button" class="dk-btn w-[104px] shrink-0 pr-2 pl-3" x-on:click="fold()" x-bind:aria-expanded="(! folded).toString()" aria-expanded="true"
                            aria-label="{{ __('Your open matches: :summary. Show or hide the tabs.', ['summary' => $summary]) }}" data-test="dock-handle">
                        <x-match-dock.cube :grey="$counts['need'] === 0" />
                        <span class="flex min-w-0 grow flex-col gap-0.5 text-left">
                            <span @class(['font-display text-base leading-4 font-bold', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0]) data-test="dock-count">{{ $handleNumber }}</span>
                            <span class="text-[11px] leading-[14px] whitespace-nowrap text-ink-2">{{ $needLabel }}</span>
                        </span>
                        <x-icon name="chevron-down" :size="16" class="text-ink-3" x-show="! folded" />
                        <x-icon name="chevron-up" :size="16" class="text-ink-3" x-show="folded" x-cloak />
                    </button>
                    <div class="flex gap-1" x-show="! folded" data-test="dock-tabs">
                        @foreach ($items as $index => $item)
                            <x-match-dock.tab :item="$item" :now-ms="$nowMs" :class="match (true) { $index === $this::TABS_LG => 'hidden xl:flex', $index > $this::TABS_LG => 'hidden', default => '' }" />
                        @endforeach
                        @foreach ([[$hiddenLg, 'flex xl:hidden'], [$hiddenXl, 'hidden xl:flex']] as [$hidden, $visibility])
                            @if ($hidden->isNotEmpty())
                                @php($hiddenNeed = $hidden->where('needsYou', true)->count())
                                <button type="button" class="dk-btn {{ $visibility }} w-24 shrink-0 flex-col items-start justify-center gap-0.5 px-2.5"
                                        x-on:click="toggle('more', $el)" x-bind:aria-expanded="(open === 'more').toString()" aria-expanded="false" aria-controls="dock-more"
                                        x-bind:class="open === 'more' && 'bg-raised'" data-test="dock-more-button">
                                    <span class="text-[13px] leading-4 font-bold">{{ __('+:count more', ['count' => $hidden->count()]) }}</span>
                                    <span @class(['text-[11px] leading-[14px] whitespace-nowrap', 'text-btc' => $hiddenNeed > 0, 'text-ink-3' => $hiddenNeed === 0])>{{ $hiddenNeed > 0 ? trans_choice(':count needs you|:count need you', $hiddenNeed) : __('waiting') }}</span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>
        @endunless

        {{-- Game pages from lg: one button in the title row, opening the whole list. --}}
        @if ($gamePage)
            @teleport('[data-dock-slot]')
                <div class="relative hidden lg:block" x-on:click.outside="open === 'slot' && close(false)" data-test="dock-slot">
                    <button type="button" class="flex h-11 cursor-pointer items-center gap-2 rounded-md border border-btc bg-btc-chip px-3 text-xs whitespace-nowrap"
                            x-on:click="toggle('slot', $el)" x-bind:aria-expanded="(open === 'slot').toString()" aria-expanded="false" aria-controls="dock-slot-list" data-test="dock-slot-button">
                        <x-match-dock.cube :grey="$counts['need'] === 0" />
                        <b @class(['font-display text-[15px]', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b>
                        <span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
                        <x-icon name="chevron-down" :size="16" class="text-ink-2" />
                    </button>
                    <div id="dock-slot-list" role="region" aria-label="{{ __('Your open matches') }}" class="dk-panel absolute top-full left-0 z-40 mt-2 w-[320px]" x-show="open === 'slot'" x-cloak>
                        <div class="flex h-[52px] items-center gap-2 border-b border-hairline pr-1 pl-4">
                            <b class="text-[13px]">{{ $summary }}</b>
                            <span class="grow"></span>
                            <button type="button" class="dk-x" x-on:click="close(true)" aria-label="{{ __('Close list') }}"><x-icon name="close" :size="16" /></button>
                        </div>
                        <div class="flex max-h-[min(420px,calc(100svh-200px))] flex-col overflow-y-auto p-1">
                            @foreach ($items as $item)
                                <x-match-dock.row :item="$item" :now-ms="$nowMs" />
                            @endforeach
                        </div>
                    </div>
                </div>
            @endteleport
        @endif

        {{-- Phones and tablets (MobileMatchDock.dc.html): the bar, or a tab on the page's own bottom bar. --}}
        <button type="button" @class([
                    'fixed inset-x-4 bottom-4 z-[35] flex h-14 cursor-pointer items-center gap-3 rounded-xl border-0 bg-card pr-3 pl-4 text-left lg:hidden',
                    'shadow-[inset_3px_0_0_var(--color-btc),0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]' => $counts['need'] > 0,
                    'shadow-[inset_3px_0_0_var(--color-line),0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]' => $counts['need'] === 0,
                ])
                x-show="! pageBar && ! keyboard" x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-test="dock-mobile-bar">
            <span class="sr-only">{{ __('Your open matches') }}: </span>
            <x-match-dock.cube :grey="$counts['need'] === 0" :size="18" />
            <span @class(['shrink-0 font-display text-lg font-bold', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</span>
            <span class="flex min-w-0 grow flex-col gap-0.5">
                <span class="truncate text-[13px] leading-4 font-bold">{{ __(':state, :name', ['state' => $first->state, 'name' => $first->name]) }}</span>
                <span class="truncate text-xs leading-4"><span @class(['text-loss' => $first->isUrgent($nowMs), 'text-ink-2' => ! $first->isUrgent($nowMs)]) @if ($first->tick) data-tick='@json($first->tick)' @endif>{{ $first->trailing }}</span><span class="text-ink-2">, {{ trans_choice(':count open|:count open', $counts['open']) }}</span></span>
            </span>
            <x-icon name="chevron-up" :size="16" class="text-ink-2" />
        </button>
        <button type="button" class="fixed left-4 z-[35] flex h-11 cursor-pointer items-center gap-2 rounded-t-lg border-0 bg-bar px-3 text-xs whitespace-nowrap text-btc shadow-[0_-1px_0_var(--color-line),-1px_0_0_var(--color-line),1px_0_0_var(--color-line),inset_0_3px_0_var(--color-btc)] lg:hidden"
                x-show="pageBar && ! keyboard && ! chatOpen" x-cloak x-bind:style="pageBar && { bottom: pageBar + 'px' }"
                x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-test="dock-bar-tab">
            <x-match-dock.cube :grey="$counts['need'] === 0" />
            <b @class(['font-display text-[15px]', 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b><span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
            <x-icon name="chevron-up" :size="16" class="text-ink-2" />
        </button>

        <div x-show="open === 'sheet'" x-cloak class="lg:hidden">
            <div aria-hidden="true" class="fixed inset-0 z-40 bg-[rgba(10,10,11,.7)]" x-on:click="close(false)"></div>
            <section role="dialog" aria-modal="true" aria-labelledby="dock-sheet-h" class="dk-sheet fixed inset-x-0 bottom-0 z-40 flex max-h-[80svh] flex-col rounded-t-2xl bg-bar shadow-[0_-1px_0_var(--color-line),0_-16px_32px_rgba(10,10,11,.8)]" data-test="dock-sheet">
                <div class="flex shrink-0 flex-col gap-2 border-b border-hairline py-2 pr-1 pl-4">
                    <span aria-hidden="true" class="h-1 w-10 self-center rounded-xs bg-edge"></span>
                    <span class="flex items-center gap-2.5">
                        <x-match-dock.cube :grey="$counts['need'] === 0" />
                        <span class="flex min-w-0 grow flex-col gap-0.5"><h2 id="dock-sheet-h" class="m-0 text-sm leading-[18px] font-bold">{{ __('Your open matches') }}</h2><span class="text-xs text-ink-2">{{ $summary }}</span></span>
                        <button type="button" class="dk-x" x-ref="sheetClose" x-on:click="close(true)" aria-label="{{ __('Close list') }}"><x-icon name="close" :size="16" /></button>
                    </span>
                </div>
                <div class="flex flex-col gap-1 overflow-y-auto pt-2 pb-4">
                    @foreach ([[$items->where('needsYou', true), trans_choice(':count needs you|:count need you', $counts['need']), 'text-btc'], [$items->where('needsYou', false), __(':count waiting', ['count' => $counts['wait']]), 'text-ink-2']] as [$group, $heading, $tone])
                        @if ($group->isNotEmpty())
                            <span class="px-4 pt-2 pb-1 text-xs font-bold {{ $tone }}">{{ $heading }}</span>
                            @foreach ($group as $item)
                                <x-match-dock.row :item="$item" :now-ms="$nowMs" variant="sheet" />
                            @endforeach
                        @endif
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
