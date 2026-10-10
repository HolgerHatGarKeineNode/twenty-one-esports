@props(['section' => null])

@php
    /*
     * The shell header, 1:1 from the design canvas (plan "Refactor und Design-Revamp", Header.dc.html, rule R4):
     * one row for every page. Desktop: 21, Spiele (the game hub), Turniere, Mempool, Season, Clans, LIVE; on the
     * right the search field, then for a player Aktionen (the match dock), the bell (history), Admin (admins
     * only) and the account; a guest gets "Anmelden" alone. Phones: 21, LIVE, search, the bell or "Anmelden",
     * and the menu (the More sheet); the tab bar at the bottom (components/shell/mobile-nav).
     * Every list is built once (App\Support\Navigation\ShellNavigation) and rendered here, in the game hub,
     * the account menu and on phones in the tab bar and the More sheet: a page linked in one is linked in all.
     * On a game page the game's context bar stays under the row (its tabs move into the shared game template in P4).
     */
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $nav->remember();
    $user = $nav->user;
    $games = $nav->playOrder();
    $active = $nav->activeGame();
    $onGamePage = $nav->onGamePage();
    $community = $nav->community();
    $chain = $nav->chain($section);
    $mempool = collect($chain)->firstWhere('key', 'mempool');
    $season = collect($chain)->firstWhere('key', 'mining');
    $tournaments = $nav->tournaments();
    $admin = $nav->admin();
    $account = $nav->account();
    $upcoming = collect($account)->firstWhere('key', 'upcoming');
    // The hub's "Yours" group first; the games the config keeps last stay at the end even when played (user 2026-10-03), marked "Yours" there.
    $tail = (array) config('esports.game_order.last', []);
    $played = array_values(array_filter($games, fn (array $game): bool => $game['played'] && ! in_array($game['slug'], $tail, true)));
    $unplayed = array_values(array_filter($games, fn (array $game): bool => ! $game['played'] || in_array($game['slug'], $tail, true)));
    $onLogin = request()->routeIs('login');
    // On the results page the field keeps what was searched.
    $searchTerm = request()->routeIs('search') ? (string) request()->string('q') : '';
    $searchLabel = __('Search players, clans or match #');
    $allGames = trans_choice('All :count game|All :count games', count($games));
@endphp

{{--
    Sticky at every width (--shell-h, revamp.css/app.css): the page scrolls under it and anchors and focus land
    below it. An open notification panel, game hub or account menu lifts it above the toast stack and the dock.
--}}
<header class="shell-header sticky top-0 z-30 shrink-0 bg-bar" style="--shell-game: {{ $active['colour'] }}" x-data="shellHeader" x-bind:style="(bell || hub || account) ? { zIndex: 55 } : {}"
        x-on:bell-toggle="bell = $event.detail" x-on:keydown.escape.window="closeSearch(); closeHub()" @if ($onGamePage) data-game-page @endif>
    <span class="shell-edge" aria-hidden="true" data-test="shell-edge"></span>

    <div class="flex h-[52px] items-center gap-1 border-b border-hairline pr-2 pl-3 lg:h-14 lg:px-6">
        <a href="{{ route('home') }}" @navigate(route('home')) class="flex h-11 min-w-11 shrink-0 items-center lg:mr-3 lg:min-w-0" aria-label="{{ __('TWENTY ONE esports, home') }}" data-test="shell-home"><span class="rv-logo size-8 lg:size-9" aria-hidden="true">21</span></a>

        <nav class="hidden shrink-0 items-center lg:flex" aria-label="{{ __('Main navigation') }}" data-test="main-nav">
            <button type="button" class="rv-nv" aria-controls="game-hub" x-bind:aria-expanded="hub.toString()" aria-expanded="false" aria-haspopup="dialog"
                    x-on:click="toggleHub($el)" title="{{ $allGames }}" data-test="games-menu">
                {{ __('Game list') }}<span class="sr-only">, {{ $allGames }}</span>
                <x-icon name="chevron-down" :size="14" />
            </button>
            <a href="{{ $tournaments['href'] }}" @navigate($tournaments['href']) @if ($section === 'tournaments') aria-current="page" @endif class="rv-nv" data-test="nav-tournaments">
                {{ $tournaments['label'] }}
                @if ($tournaments['open'] > 0)
                    <span class="rv-n" data-test="tournaments-open"><span class="sr-only">, </span>{{ $tournaments['open'] }}<span class="sr-only"> {{ trans_choice('open for sign-up|open for sign-up', $tournaments['open']) }}</span></span>
                @endif
            </a>
            <a href="{{ $mempool['href'] }}" @navigate($mempool['href']) @if ($mempool['current']) aria-current="page" @endif aria-label="{{ $mempool['name'] }}" class="rv-nv" data-chain-key="mempool" data-test="nav-mempool">
                <x-icon name="matches" :size="14" class="max-xl:hidden" />
                <span>{{ $mempool['label'] }}</span>
                @if ($mempool['count'] !== null)
                    <span class="rv-n" aria-hidden="true" data-test="mempool-count">{{ $mempool['count'] }}</span>
                @endif
            </a>
            <a href="{{ $season['href'] }}" @navigate($season['href']) @if ($season['current']) aria-current="page" @endif aria-label="{{ $season['name'] }}" class="rv-nv" data-test="nav-mining">
                <span>{{ $season['label'] }}</span>
                @if ($season['tag'] !== null)
                    <span class="rv-tag max-xl:hidden" data-test="season-tag">{{ $season['tag'] }}</span>
                @endif
            </a>
            @foreach ($community as $link)
                <a href="{{ $link['href'] }}" @navigate($link['href']) @if ($section === $link['key']) aria-current="page" @endif class="rv-nv" data-test="nav-{{ $link['key'] }}">{{ $link['label'] }}</a>
            @endforeach
            {{-- The stream (P20): its LIVE mark with the viewers on air, a quiet "Live" link like its neighbours off air. --}}
            <x-live-badge class="rv-nv" off-air />
        </nav>

        {{-- Phones: the stream's LIVE mark next to the logo (Header.dc.html "Handy"). --}}
        <x-live-badge class="flex lg:hidden" compact />

        <span class="grow"></span>

        {{-- The site search (P16, SearchController): from lg a field in the row, on phones a button that opens the field under it. "/" focuses it. --}}
        <form method="GET" action="{{ route('search') }}" role="search" class="rv-srch mr-2 hidden lg:flex" data-test="search-inline">
            <x-icon name="search" :size="14" class="shrink-0" />
            <label for="site-search-inline" class="sr-only">{{ $searchLabel }}</label>
            <input id="site-search-inline" name="q" x-ref="searchInline" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search the site') }}"
                   title="{{ $searchLabel }} (/)" aria-keyshortcuts="/">
        </form>
        <button type="button" class="rv-ib lg:hidden" aria-controls="mobile-search" x-bind:aria-expanded="search.toString()" aria-expanded="false" aria-keyshortcuts="/" title="{{ __('Search') }} (/)"
                x-ref="searchToggle" x-on:click="search = ! search; closeHub(false); search && $nextTick(() => $refs.searchField.focus())" data-test="mobile-search-toggle">
            <x-icon name="search" :size="20" />
            <span class="sr-only">{{ __('Search') }}</span>
        </button>

        @if ($user)
            {{-- Aktionen: the match dock's entries (its own count, components/⚡match-dock), the dock opens on click; empty, the player's page. --}}
            <a href="{{ route('dashboard') }}" class="rv-act hidden lg:inline-flex" x-data="dockCount" x-on:click="openDock($event)"
               x-bind:aria-label="dockOpen > 0 ? @js(__('Actions')).concat(', ', dockOpen, ' ', @js(__('open'))) : @js(__('Actions'))" data-test="nav-actions">
                <x-icon name="bolt" :size="14" class="text-btc" />
                <span class="max-xl:hidden">{{ __('Actions') }}</span>
                <span class="rv-n is-o" x-show="dockOpen > 0" x-text="dockOpen" x-cloak data-test="actions-count"></span>
            </a>

            {{-- An open cup match on every page (CupMatchNow; user, 2026-10-03): pulsing while the game is live. --}}
            <livewire:cup-match variant="badge" />

            <livewire:notification-bell />

            @if ($admin)
                <a href="{{ $admin['href'] }}" @navigate($admin['href']) @if ($section === 'admin') aria-current="page" @endif class="rv-nv hidden h-11 lg:inline-flex" title="{{ __('Admin') }}" data-test="account-admin">
                    <x-icon name="shield-check" :size="14" />
                    <span class="max-xl:sr-only">{{ __('Admin') }}</span>
                    @if ($admin['count'] > 0)
                        <span class="rv-n is-r" data-test="admin-count"><span class="sr-only">, </span>{{ $admin['count'] }}<span class="sr-only"> {{ trans_choice('open case|open cases', $admin['count']) }}</span></span>
                    @endif
                </a>
            @endif

            {{--
                The account menu: a menu button (WAI-ARIA pattern, shellHeader in resources/js/shellNav.js). Enter, Space or
                ArrowDown open it on the first item, ArrowUp on the last; arrows, Home and End move; Escape, Tab or a click outside close it.
            --}}
            <div class="relative ml-1 hidden lg:block" x-on:click.outside="closeAccount(false)" x-on:keydown.escape="if (account) { $event.stopPropagation(); closeAccount() }" x-on:focusout="accountFocusOut($event)">
                <button type="button" title="{{ $user->displayName() }} · {{ $user->shortNpub() }}" class="flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-[4px] px-1 text-ink-3 hover:text-ink"
                        aria-haspopup="menu" aria-controls="account-menu" aria-expanded="false" x-bind:aria-expanded="account.toString()" x-ref="accountChip"
                        x-on:click="toggleAccount()" x-on:keydown.down.prevent="openAccount('first')" x-on:keydown.up.prevent="openAccount('last')" data-test="account-chip">
                    <x-avatar :user="$user" :size="32" class="shrink-0 rounded-full" />
                    <span class="sr-only">{{ __('You') }}</span><span class="sr-only" data-test="account-chip-name">, {{ $user->displayName() }}</span>
                    @if ($upcoming)
                        {{-- Open match rooms and registered tournaments (UpcomingEvents), listed first in the menu. --}}
                        <span class="rv-n" data-test="upcoming-count"><span class="sr-only">, </span>{{ $upcoming['count'] }}<span class="sr-only"> {{ trans_choice('upcoming match or event|upcoming matches and events', $upcoming['count']) }}</span></span>
                    @endif
                    <x-icon name="chevron-down" :size="14" />
                </button>

                <div id="account-menu" role="menu" aria-label="{{ __('Account') }}" tabindex="-1" x-ref="accountMenu" x-show="account" x-cloak data-nav-panel
                     x-on:keydown="accountKey($event)" data-test="account-menu"
                     class="absolute top-full right-0 z-50 mt-[5px] w-max max-w-72 min-w-48 rounded-[4px] border border-line bg-row-hover p-1 shadow-[0_8px_24px_rgba(0,0,0,.5)] focus:outline-hidden">
                    <div class="flex items-center gap-2.5 px-2 pt-1.5 pb-2" data-test="account-menu-name">
                        <x-avatar :user="$user" :size="32" class="shrink-0 rounded-full" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="text-sm font-bold break-words text-ink">{{ $user->displayName() }}</span>
                            <span class="text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                        </span>
                    </div>
                    <div class="-mx-1 my-1 h-px bg-line" role="separator"></div>
                    @foreach ($account as $link)
                        <a href="{{ $link['href'] }}" @navigate($link['href']) role="menuitem" tabindex="-1" class="account-item" @if ($link['test']) data-test="{{ $link['test'] }}" @endif><x-icon :name="$link['icon']" :size="16" class="me-2 text-ink-3" />{{ $link['label'] }}@isset($link['count'])<span class="rv-n ms-auto">{{ $link['count'] }}</span>@endisset</a>
                    @endforeach
                    <div class="-mx-1 my-1 h-px bg-line" role="separator"></div>
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()">
                        @csrf
                        <button type="submit" role="menuitem" tabindex="-1" class="account-item cursor-pointer"><x-icon name="logout" :size="16" class="me-2 text-ink-3" />{{ __('Log out') }}</button>
                    </form>
                </div>
            </div>
        @else
            <a href="{{ route('login') }}" @if ($onLogin) aria-current="page" @endif class="group flex h-11 shrink-0 items-center" data-test="mobile-login"><span class="rv-b is-sm h-9 group-hover:border-btc-hi group-hover:bg-btc-hi">{{ __('Log in') }}</span></a>
        @endif

        {{-- Phones: the More sheet (Season, Clans, rules, account, Admin) opens from here (Header.dc.html "Handy"). --}}
        <button type="button" class="rv-ib lg:hidden" aria-controls="more-sheet" x-bind:aria-expanded="more.toString()" aria-expanded="false" aria-haspopup="dialog"
                x-on:click="window.dispatchEvent(new CustomEvent('more-toggle', { detail: $el }))" data-test="tab-more">
            <x-icon name="menu" :size="20" />
            <span class="sr-only">{{ __('More') }}@if ($upcoming), {{ trans_choice(':count upcoming match or event|:count upcoming matches and events', $upcoming['count']) }}@endif</span>
            @if ($upcoming)
                <span class="tab-dot" aria-hidden="true" data-test="tab-more-dot"></span>
            @endif
        </button>
    </div>

    @if ($onGamePage)
        {{-- Row 2 (from lg, game pages): the context bar of the active game, until P4 puts its tabs into the shared game template. --}}
        <nav class="ctx hidden h-12 items-center gap-1 border-b border-hairline px-6 lg:flex" style="--game: {{ $active['colour'] }}" aria-label="{{ $active['name'] }}" data-test="context-bar" data-game="{{ $active['slug'] }}">
            <span class="ctx-name">{{ $active['name'] }}</span>
            <x-game-credit :game="$active['slug']" class="-ms-2 me-3 whitespace-nowrap" />
            @foreach ($active['actions'] as $link)
                <a href="{{ $link['href'] }}" @navigate($link['href']) @if (\App\Support\Navigation\ShellNavigation::isCurrent($link)) aria-current="page" @endif @class(['ctx-link', 'ctx-link-accent' => $link['accent'] ?? false]) data-test="ctx-{{ $link['key'] }}">
                    <x-icon :name="$link['icon']" :size="16" class="max-[90rem]:hidden" />
                    <span class="min-[90rem]:hidden">{{ $link['short'] }}</span><span class="max-[90rem]:hidden">{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    {{-- The search row, phones only: from lg the field stands in row 1. --}}
    <div id="mobile-search" class="border-b border-hairline px-4 py-3 lg:hidden" x-show="search" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <form method="GET" action="{{ route('search') }}" role="search">
            <label for="site-search" class="sr-only">{{ __('Search') }}</label>
            <input id="site-search" name="q" x-ref="searchField" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ $searchLabel }}"
                   aria-keyshortcuts="/" class="h-11 w-full rounded-[4px] border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
        </form>
    </div>

    <x-shell.game-hub :played="$played" :unplayed="$unplayed" :count="count($games)" />
</header>

{{--
    Guests: "New here?" right under the header, outside it, on pages the revamp has not redrawn yet. The start page
    and the login page carry their own way in (Mitspielen, "Danach"), so the strip stays off there.
--}}
@guest
    @unless (request()->routeIs('home', 'login'))
        <x-shell.first-steps />
    @endunless
@endguest

<x-shell.mobile-nav :active="$active" :games="$games" :community="$community" :chain="$chain" :tournaments="$tournaments" :admin="$admin" :account="$account" :section="$section" :user="$user" :on-game-page="$onGamePage" />
