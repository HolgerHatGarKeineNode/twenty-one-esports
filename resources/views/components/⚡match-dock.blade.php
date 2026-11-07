<?php

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\Tournaments\CupMatchNow;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The match dock of a logged-in player: every open match and reason to act.
 * Two ways in, one state (resources/js/matchDock.js `open`), so opening one
 * closes the other:
 *
 *  - the floating dock (P5f, MatchDock.dc.html from 1024 px, MobileMatchDock.dc.html
 *    below), back on every page but a game's (user, 2026-10-14, after
 *    DerCaddy missed it: "die wäre ja auch gut, wenn sie immer zu sehen ist"):
 *    one tab per open match at the bottom right with a handle, "+N more" and a
 *    panel per match (the `panel` island, loaded when a tab first opens); on a
 *    phone a bar above the tab bar that opens the sheet; the open cup match
 *    floats above both;
 *  - the action dock (P7, ActionDock.dc.html, ActionDockPhone.dc.html): the
 *    header's and the tab bar's "Aktionen" open the grouped panel or sheet.
 *
 * What counts as open, and in which order, is App\Support\Dock\OpenMatches.
 * Live updates: the player's private channel and the watch channel of each chess
 * game on the dock ask for `$refresh`, without a websocket a poll does.
 *
 * The page the player is on is read once, at mount: its game or series never
 * has an entry. A running game's page and a page drawn with the game templates
 * (a lobby, a ladder) keep nothing floating (P7: on a 375 x 667 phone nothing
 * covers their ways to play, at 1440 nothing covers a board game's chat
 * composer). A running game's title row gets a button (from lg), and a page that
 * owns the bottom edge gets a tab on its bar (phones). Whatever floats carries
 * `data-live-floor`: the live player (P20, resources/js/livePlayer.js) lifts itself above it.
 */
new class extends Component {
    /** Tabs that fit next to the handle: three from lg, four from xl. */
    public const TABS_LG = 3;

    public const TABS_XL = 4;

    #[Locked]
    public ?int $excludeGame = null;

    #[Locked]
    public ?int $excludeSeries = null;

    /** The board game on screen (plan "Mühle und Dame", P5), which never has a tab. */
    #[Locked]
    public ?int $excludeBoard = null;

    /** On a running game page: the dock goes into the title row from lg. */
    #[Locked]
    public bool $gamePage = false;

    /**
     * A page drawn with the game templates (x-game.subnav: a lobby, a ladder): its ways to play stay free, so nothing
     * floats there either (rule R3; the floating bar covered "Herausfordern" on /games/rocket-league at 375 x 667 and
     * "Team" on /chess at 1024 x 768, measured 2026-10-14). The header's and the tab bar's "Aktionen" stay.
     */
    #[Locked]
    public bool $gameTemplate = false;

    /** On the player's own live blitz board: no ring, no pop-up. */
    #[Locked]
    public bool $quiet = false;

    /** The page's path, read at mount: the cup entry stays off the page it leads to. */
    #[Locked]
    public string $path = '';

    public function mount(): void
    {
        ['game' => $this->excludeGame, 'series' => $this->excludeSeries] = OpenMatches::onScreen(request());
        $this->excludeBoard = OpenMatches::boardOnScreen(request());
        $this->path = '/'.ltrim(request()->path(), '/');
        // Set by components/game/subnav while the page renders, before the shell's dock mounts (as the header reads it).
        $this->gameTemplate = request()->attributes->get('game-subnav', false) === true;

        if ($this->excludeGame !== null) {
            $game = ChessGame::query()->find($this->excludeGame);
            $this->gamePage = $game?->isActive() === true;
            $this->quiet = $this->gamePage && ! $game->isCorrespondence() && $game->colorOf($this->user()) !== null;
        }

        // A running board game's page has the title-row slot too: the floating bar covered its chat's send button at 1440x900 (PR #5).
        if ($this->excludeBoard !== null) {
            $this->gamePage = BoardGame::query()->find($this->excludeBoard)?->isActive() === true;
        }
    }

    /**
     * @return Collection<int, DockItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return app(OpenMatches::class)->for($this->user(), $this->excludeGame, $this->excludeSeries, $this->excludeBoard);
    }

    /**
     * The player's open cup match (CupMatchNow; user, 2026-10-03): the
     * dock's first entry, highlighted. Not on the page it leads to.
     *
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    #[Computed]
    public function cup(): ?array
    {
        $cup = app(CupMatchNow::class)->for($this->user());

        return $cup === null || parse_url($cup['href'], PHP_URL_PATH) === $this->path ? null : $cup;
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
    $cup = $this->cup;
    $viewer = auth()->user();
    $first = $items->first(fn ($item) => $item->needsYou) ?? $items->first();
    $hiddenLg = $items->slice($this::TABS_LG);
    $hiddenXl = $items->slice($this::TABS_XL);
    $needLabel = $counts['need'] > 0 ? trans_choice('need you|need you', $counts['need']) : __('waiting');
    $handleNumber = $counts['need'] > 0 ? $counts['need'] : $counts['wait'];
    $summary = __(':need need you, :open open', ['need' => $counts['need'], 'open' => $counts['open']]);
    // ActionDock.dc.html "Gruppen": Jetzt (running now or under its limit), Du bist dran, Einladungen, Demnächst.
    $isInvite = fn ($item): bool => str_ends_with($item->kind, '_invite') || str_ends_with($item->kind, '_challenge');
    $nowItems = $items->filter(fn ($item) => $item->needsYou && ($item->isLive() || $item->isUrgent($nowMs)));
    $rest = $items->reject(fn ($item) => $nowItems->contains('key', $item->key));
    $groups = [
        ['now', __('Now'), $nowItems],
        ['turn', __('Your turn'), $rest->filter(fn ($item) => $item->needsYou && ! $isInvite($item))],
        ['invites', __('Invitations'), $rest->filter(fn ($item) => $item->needsYou && $isInvite($item))],
        ['soon', __('Coming up'), $rest->reject(fn ($item) => $item->needsYou)],
    ];
    // The header's "Aktionen" turns red with the entry under its limit (ActionDock.dc.html "Zu, Frist unter 15 Min").
    $urgent = $nowItems->filter(fn ($item) => $item->isUrgent($nowMs))->sortBy(fn ($item) => $item->tick['endsAt'])->first();
    $config = [
        'now' => $nowMs,
        'need' => $counts['need'],
        'poll' => (int) config('esports.dock.poll_seconds'),
        'pollWithSocket' => (int) config('esports.dock.poll_seconds_with_socket'),
        'quiet' => $quiet,
        'labels' => ['hm' => __(':h h :m'), 'min' => __(':m min'), 'turned' => __('Your move: :sentence')],
    ];
@endphp

{{--
    The floating dock (MatchDock.dc.html, MobileMatchDock.dc.html) on every page but a running game's, and the action
    dock (ActionDock.dc.html from 1024 px: a panel under the header on the right; ActionDockPhone.dc.html: a sheet over
    the tab bar; rules R4, R12, R17), opened by the header's and the tab bar's "Aktionen" and by the phone's floating bar.
    The sheet groups the entries Jetzt, Du bist dran, Einladungen, Demnächst, each one card with its one action. On a
    running game page the title row keeps its own button (from lg); a page that owns the bottom edge gets a tab on its
    bar (phones).
--}}
{{-- The config rides in its own attribute, so the x-data expression never changes: a refresh morphs the dock instead of starting it anew (an open sheet stayed open, P7). --}}
<div x-data="matchDock(JSON.parse($el.dataset.config))" data-config="{{ json_encode($config) }}" data-need="{{ $counts['need'] }}" data-open="{{ $counts['open'] }}" data-cup="{{ $cup ? '1' : '0' }}" data-test="match-dock-root"
     @if ($urgent) data-urgent='@json(['label' => $urgent->state, 'tick' => $urgent->tick])' @elseif ($cup && $cup['state'] === 'live') data-urgent='@json(['label' => $cup['short'], 'tick' => null])' @endif
     x-on:keydown.escape.window="close(true)" x-on:dock-open.window="openFromHeader() && $event.detail?.preventDefault()" x-on:bell-toggle.window="$event.detail && close(false)" x-on:chat-sheet-toggle.window="chatToggled($event.detail)">
    <span class="sr-only" aria-live="polite" x-text="announcement"></span>

    @if ($items->isNotEmpty() || $cup)
        {{--
            Every item once, for resources/js/matchDock.js (which turned to "on you", which chess
            games to watch) and alerts.js (no toast for a game that already has an entry).
        --}}
        @foreach ($items as $item)
            <span hidden wire:key="item-{{ $item->key }}" data-dock-item="{{ $item->key }}" data-need="{{ $item->needsYou ? '1' : '0' }}" data-sentence="{{ $item->sentence }}"
                  @if ($item->gameId()) data-dock-game="{{ $item->gameId() }}" @endif></span>
        @endforeach

        @unless ($gamePage || $gameTemplate)
            {{--
                The open cup match first, above the dock's own bar and highlighted (user, 2026-10-03: in a live cup
                players could not find their matches). It floats above the bar where there is one: the phone's bar
                (56 px) or, from lg, the desktop bar. A page that owns the bottom edge on a phone (the chat sheet, a
                score bar) keeps it in the sheet instead, so nothing floats over its ways to play.
            --}}
            @if ($cup)
                <div aria-hidden="true" class="h-16" x-show="! pageBar" data-test="dock-cup-spacer"></div>
                <a href="{{ $cup['href'] }}" wire:key="dock-cup-{{ $cup['key'] }}"
                   @class([
                       'fixed inset-x-4 z-[35] flex h-14 items-center gap-3 rounded-xl pr-3 pl-3 lg:right-6 lg:left-auto lg:w-[380px]',
                       'bottom-[calc(var(--tabbar-h)+5rem)] lg:bottom-[88px]' => $items->isNotEmpty(),
                       'bottom-[calc(var(--tabbar-h)+1rem)] lg:bottom-4' => $items->isEmpty(),
                       'bg-btc text-on-btc shadow-[0_0_0_1px_var(--color-btc-hi),0_16px_32px_rgba(10,10,11,.8)] hover:text-on-btc' => $cup['state'] === 'live',
                       'bg-card text-ink shadow-[inset_3px_0_0_var(--color-btc),0_0_0_2px_var(--color-btc),0_16px_32px_rgba(10,10,11,.8)] hover:text-ink' => $cup['state'] !== 'live',
                   ])
                   x-show="! pageBar && ! keyboard && ! chatOpen"
                   data-test="dock-cup" data-state="{{ $cup['state'] }}" data-live-floor>
                    <span @class(['relative flex size-9 shrink-0 items-center justify-center rounded-lg', 'bg-on-btc text-btc' => $cup['state'] === 'live', 'bg-btc text-on-btc' => $cup['state'] !== 'live'])>
                        <x-icon name="trophy" :size="18" />
                        @if ($cup['state'] === 'live')
                            <span aria-hidden="true" class="absolute -top-1 -right-1 flex size-3.5 items-center justify-center rounded-full bg-btc"><span class="size-2.5 animate-live rounded-full bg-live"></span></span>
                        @endif
                    </span>
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="truncate text-[13px] leading-4" data-test="dock-cup-label">{{ $cup['label'] }}</b>
                        <span @class(['truncate text-xs leading-4', 'text-on-btc' => $cup['state'] === 'live', 'text-ink-2' => $cup['state'] !== 'live'])>{{ $cup['tournament'] }}, {{ $cup['round'] }}</span>
                    </span>
                    <x-icon name="next" :size="18" class="shrink-0" />
                </a>
            @endif

            @if ($items->isNotEmpty())
                {{-- Room below the footer while the dock floats, so the page end never sits under it. --}}
                <div aria-hidden="true" class="h-[88px]" x-show="! pageBar" data-test="dock-spacer"></div>

                {{-- Desktop bar (MatchDock.dc.html): the handle, a tab per open match, "+N more"; a tab opens its panel. --}}
                <section aria-label="{{ __('Your open matches') }}" class="fixed right-6 bottom-4 z-[35] hidden lg:block" data-test="match-dock" data-live-floor
                         x-ref="bar" x-on:mouseenter="inside = true" x-on:mouseleave="inside = false; flush()" x-on:focusin="inside = true" x-on:focusout="leaveFocus($event)"
                         x-on:click.outside="ownsOpen() && close(false)" x-on:keydown.left="step(-1, $event)" x-on:keydown.right="step(1, $event)">
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
                            {{-- A panel names the other player's clan: loaded for every face at once (lazy loading is off), after the
                                 placeholder so a page whose panels are skipped does not pay for it. --}}
                            @php((new \Illuminate\Database\Eloquent\Collection($this->items->pluck('face')->filter()->unique('id')->values()->all()))->loadMissing('clanMember.clan'))
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
                                    <x-match-dock.row :item="$item" :class="$index < $this::TABS_XL ? 'xl:hidden' : ''" />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex gap-1 rounded-lg bg-card p-1 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]">
                        <button type="button" class="dk-btn min-w-[104px] shrink-0 pr-2 pl-3" x-on:click="fold()" x-bind:aria-expanded="(! folded).toString()" aria-expanded="true"
                                aria-label="{{ __('Your open matches: :summary. Show or hide the tabs.', ['summary' => $summary]) }}" data-test="dock-handle">
                            <x-match-dock.cube :grey="$counts['need'] === 0" />
                            <span class="flex min-w-0 grow flex-col gap-0.5 text-left">
                                <span @class(['font-display text-base leading-4 font-bold', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0]) data-test="dock-handle-count">{{ $handleNumber }}</span>
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

                {{-- Phones and tablets (MobileMatchDock.dc.html): the bar, 16 px above the shell's tab bar (--tabbar-h); it opens the sheet. --}}
                <button type="button" @class([
                            'fixed inset-x-4 bottom-[calc(var(--tabbar-h)+1rem)] z-[35] flex h-14 cursor-pointer items-center gap-3 rounded-xl border-0 bg-card pr-3 pl-4 text-left lg:hidden',
                            'shadow-[inset_3px_0_0_var(--color-btc),0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]' => $counts['need'] > 0,
                            'shadow-[inset_3px_0_0_var(--color-line),0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]' => $counts['need'] === 0,
                        ])
                        x-show="! pageBar && ! keyboard" x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-test="dock-mobile-bar" data-live-floor>
                    <span class="sr-only">{{ __('Your open matches') }}: </span>
                    <x-match-dock.cube :grey="$counts['need'] === 0" :size="18" />
                    <span @class(['shrink-0 font-display text-lg font-bold', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0]) data-test="dock-mobile-count">{{ $handleNumber }}</span>
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="truncate text-[13px] leading-4 font-bold">{{ __(':state, :name', ['state' => $first->state, 'name' => $first->name]) }}</span>
                        <span class="truncate text-xs leading-4"><span @class(['text-loss' => $first->isUrgent($nowMs), 'text-ink-2' => ! $first->isUrgent($nowMs)]) @if ($first->tick) data-tick='@json($first->tick)' @endif>{{ $first->trailing }}</span><span class="text-ink-2">, {{ trans_choice(':count open|:count open', $counts['open']) }}</span></span>
                    </span>
                    <x-icon name="chevron-up" :size="16" class="text-ink-2" />
                </button>
            @endif
        @endunless

        {{-- Game pages from lg: one button in the title row, opening the whole list. --}}
        @if ($gamePage && $items->isNotEmpty())
            @teleport('[data-dock-slot]')
                <div class="relative hidden lg:block" data-test="dock-slot">
                    <button type="button" class="flex h-11 cursor-pointer items-center gap-2 rounded-[4px] border border-btc bg-btc-chip px-3 text-xs whitespace-nowrap"
                            x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-controls="dock-sheet" data-test="dock-slot-button">
                        <x-icon name="bolt" :size="14" class="text-btc" />
                        <b @class(['font-display text-[15px]', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b>
                        <span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
                    </button>
                </div>
            @endteleport
        @endif

        {{-- A page that owns the bottom edge (the daily move bar, the chat sheet): a tab on its bar, phones only. --}}
        <button type="button" class="fixed left-4 z-[35] flex h-11 cursor-pointer items-center gap-2 rounded-t-lg border-0 bg-bar px-3 text-xs whitespace-nowrap text-btc shadow-[0_-1px_0_var(--color-line),-1px_0_0_var(--color-line),1px_0_0_var(--color-line),inset_0_3px_0_var(--color-btc)] lg:hidden"
                x-show="pageBar && ! row && ! keyboard && ! chatOpen" x-cloak x-bind:style="pageBar && { bottom: pageBar + 'px' }"
                x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-test="dock-bar-tab" data-live-floor>
            <x-match-dock.cube :grey="$counts['need'] === 0" />
            <b @class(['font-display text-[15px]', 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b><span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
            <x-icon name="chevron-up" :size="16" class="text-ink-2" />
        </button>

        {{--
            Beside the closed chat bar, in its row (DerCaddy, 2026-10-10: "10 wartet" next to the chat bar, each opening its
            own sheet): the row's left end, as tall as the row, with the same grip as the chat sheet. The row leaves it room
            (resources/js/matchDock.js, --dock-row-w). On the sheet's layer and after it in the page, so it paints over the
            chat bar and under both sheets and their shade.
        --}}
        <button type="button" class="fixed left-0 z-40 flex max-w-[40%] cursor-pointer flex-col items-stretch gap-2 rounded-tl-2xl border-0 bg-bar pt-2 pr-3 pb-3 pl-4 text-xs whitespace-nowrap text-btc shadow-[inset_-1px_0_0_var(--color-hairline)] lg:hidden"
                x-show="row && ! keyboard && ! chatOpen" x-cloak x-bind:style="row && { bottom: row.bottom + 'px', height: row.height + 'px' }"
                x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-dock-row-tab data-test="dock-row-tab" data-live-floor>
            <span aria-hidden="true" class="h-1 w-10 self-center rounded-xs bg-edge"></span>
            {{-- At most 40 % of the row: on a narrow phone a long label ("2 need you") gives way before the chat does. --}}
            <span class="flex min-w-0 items-center gap-2">
                <x-match-dock.cube :grey="$counts['need'] === 0" />
                <b @class(['shrink-0 font-display text-[15px]', 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b><span class="min-w-0 truncate text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
                <x-icon name="chevron-up" :size="16" class="shrink-0 text-ink-2" />
            </span>
        </button>

        <div x-show="open === 'sheet'" x-cloak>
            <div aria-hidden="true" class="fixed inset-0 z-40 bg-[rgba(10,10,11,.7)] lg:top-14 lg:bg-[rgba(10,10,11,.55)]" x-on:click="close(false)"></div>
            <section id="dock-sheet" role="dialog" aria-modal="true" aria-labelledby="dock-sheet-h" class="dk-drawer" data-test="dock-sheet">
                <div class="flex shrink-0 flex-col gap-2 border-b border-hairline pt-2 pr-1 pb-3 pl-4 lg:pt-4">
                    <span aria-hidden="true" class="h-1 w-10 self-center rounded-[2px] bg-edge lg:hidden"></span>
                    <span class="flex items-center gap-2.5">
                        <h2 id="dock-sheet-h" class="m-0 font-display text-[18px] font-bold">{{ __('Actions') }}</h2>
                        @if ($counts['need'] > 0)
                            <span class="rv-n is-r" data-test="dock-count">{{ $counts['need'] }}</span>
                        @endif
                        <span class="rv-m">{{ $counts['need'] > 0 ? trans_choice('need you|need you', $counts['need']) : __('waiting') }}</span>
                        <span class="grow"></span>
                        <button type="button" class="dk-x" x-ref="sheetClose" x-on:click="close(true)" aria-label="{{ __('Close list') }}"><x-icon name="close" :size="16" /></button>
                    </span>
                    <span class="sr-only">{{ $summary }}</span>
                </div>
                <div class="flex flex-col gap-2 overflow-y-auto px-3 pt-3 pb-4">
                    @foreach ($groups as [$key, $heading, $group])
                        @if ($group->isNotEmpty() || ($key === 'now' && $cup))
                            <h3 class="m-0 flex items-center gap-2 px-1 pt-2 text-[12px] font-normal text-ink-2" data-test="dock-group-{{ $key }}">
                                @if ($key === 'now')<span class="rv-live h-5 w-7 justify-center px-0" aria-hidden="true"><i></i></span>@endif{{ $heading }}
                            </h3>
                            @if ($key === 'now' && $cup)
                                {{-- The open cup match (CupMatchNow; user, 2026-10-03): the first entry, highlighted. --}}
                                <div wire:key="dock-cup-card-{{ $cup['key'] }}" class="dk-card is-now" data-test="dock-cup-card" data-state="{{ $cup['state'] }}">
                                    <span class="flex h-10 w-[72px] shrink-0 items-center justify-center rounded-[2px] bg-btc-tint text-btc" aria-hidden="true"><x-icon name="trophy" :size="20" /></span>
                                    <a href="{{ $cup['href'] }}" class="flex min-w-0 grow flex-col gap-0.5 text-ink hover:text-ink">
                                        <b class="dk-card-title" data-test="dock-cup-card-label">{{ $cup['label'] }}</b>
                                        <span class="truncate text-[12px] text-ink-2">{{ $cup['tournament'] }}, {{ $cup['round'] }}</span>
                                    </a>
                                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                                        @if ($cup['state'] === 'live')
                                            <span class="rv-live h-5"><i></i>{{ __('live') }}</span>
                                        @endif
                                        <a href="{{ $cup['href'] }}" class="rv-b is-sm">{{ $cup['short'] }}</a>
                                    </span>
                                </div>
                            @endif
                            @foreach ($group as $item)
                                <x-match-dock.card :item="$item" :now-ms="$nowMs" :now="$key === 'now'" />
                            @endforeach
                        @endif
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
