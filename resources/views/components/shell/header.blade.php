@props(['section' => null])

@php
    /*
     * The shell navigation, concept B "game tabs" (2026-09-27). Every list is
     * built once here (App\Support\Navigation\ShellNavigation) and rendered by
     * row 1, the context bar (row 2), the game hub, the account menu and, on
     * phones, the game chips, the tab bar and the More sheet: a page linked in
     * one is linked in all (P16, tests/Browser/NavigationMenusTest.php).
     */
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $nav->remember();
    $user = $nav->user;
    $games = $nav->games();
    $tabs = $nav->tabs();
    $active = $nav->activeGame();
    $onGamePage = $nav->onGamePage();
    $community = $nav->community();
    $tournaments = $nav->tournaments();
    $seasonTag = $nav->seasonTag();
    $admin = $nav->admin();
    $account = $nav->account();
    $played = array_values(array_filter($games, fn (array $game): bool => $game['played']));
    $unplayed = array_values(array_filter($games, fn (array $game): bool => ! $game['played']));
    $current = request()->fullUrl();
    $onLogin = request()->routeIs('login');
    // On the results page the field keeps what was searched.
    $searchTerm = request()->routeIs('search') ? (string) request()->string('q') : '';
    $allGames = trans_choice('All :count game|All :count games', count($games));
@endphp

{{-- An open notification panel or game hub lifts the header above the toast stack (z-50) and the match dock (z-35). --}}
<header class="relative z-30 shrink-0 bg-bar" x-data="shellHeader" x-bind:style="(bell || hub) ? 'z-index: 55' : ''"
        x-on:bell-toggle="bell = $event.detail" x-on:keydown.escape.window="search = false; closeHub()">
    {{--
        Row 1. Below lg it is the phone's only top bar (56 px: logo, game chips, search, bell or
        "Log in"); from lg the desktop bar (64 px): logo, game tabs, the hub,
        Clans, Season, search, then the account side. How many game tabs show
        depends on the width (app.css `.gtab`), so 1024 px never overflows.
    --}}
    <div class="flex h-14 items-center gap-1 border-b border-hairline pr-2 pl-4 lg:h-16 lg:gap-2 lg:px-6 xl:px-8">
        <a href="{{ route('home') }}" class="flex min-h-11 min-w-11 shrink-0 items-center gap-2.5 text-ink hover:text-ink" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="32" class="shadow-none lg:hidden" />
            <x-logo :size="36" class="max-lg:hidden" />
            {{-- The word mark gives its room to the game tabs on desktop until the widest tier. --}}
            <span class="flex items-baseline gap-1.5 whitespace-nowrap max-md:sr-only lg:max-[120rem]:sr-only">
                <span class="font-display text-sm font-extrabold tracking-[0.02em] lg:text-base">TWENTY ONE</span>
                <span class="text-xs text-ink-2 max-sm:hidden">esports</span>
            </span>
        </a>

        {{--
            Phones: every game as a chip, inside the top bar (one 56 px bar, not two), the active one scrolled
            into view; the last chip opens the hub. Chips carry the short label (Chess, RL, FC27) and the full
            name as their accessible name: with full names "Rocket League" (175 px) was wider than the whole
            chip row (129 px for a guest at 375). Below 360 px the thumbnail and the grid icon step aside.
        --}}
        <nav class="flex min-w-0 flex-1 snap-x snap-mandatory gap-2 overflow-x-auto px-1 [scrollbar-width:none] [mask-image:linear-gradient(90deg,#000_calc(100%-24px),transparent)] lg:hidden" aria-label="{{ __('Game titles') }}" id="game-chips" x-ref="chips">
            @foreach ($games as $game)
                <a href="{{ $game['page'] }}" style="--game: {{ $game['colour'] }}" class="gchip" aria-label="{{ $game['short'] === $game['name'] ? $game['name'] : $game['short'].', '.$game['name'] }}" title="{{ $game['name'] }}"
                   @if ($game['slug'] === $active['slug']) aria-current="{{ $onGamePage ? 'page' : 'true' }}" @endif
                   @if ($game['slug'] !== 'chess') data-test="mobile-{{ $game['slug'] }}" @endif>
                    {{-- Eager: the chips are the top bar of every phone page, above the fold by definition. --}}
                    <x-game-cover :game="$game['slug']" size="thumb" loading="eager" class="w-8 rounded-xs max-[359px]:hidden" />
                    <span aria-hidden="true">{{ $game['short'] }}</span>
                </a>
            @endforeach
            <button type="button" class="gchip" aria-label="{{ $allGames }}" aria-controls="game-hub" x-bind:aria-expanded="hub.toString()" aria-expanded="false" aria-haspopup="dialog" x-on:click="toggleHub($el)" data-test="mobile-games-menu">
                <x-icon name="grid" :size="18" class="text-ink-3 max-[359px]:hidden" />
                <span aria-hidden="true">{{ __('All :count', ['count' => count($games)]) }}</span>
            </button>
        </nav>

        <nav class="hidden h-16 min-w-0 grow items-stretch gap-1 lg:ml-2 lg:flex xl:ml-4" aria-label="{{ __('Main navigation') }}" data-test="game-tabs">
            @foreach ($tabs as $index => $tab)
                <a href="{{ $tab['page'] }}" style="--game: {{ $tab['colour'] }}"
                   @if ($tab['slug'] === $active['slug']) aria-current="{{ $onGamePage ? 'page' : 'true' }}" @endif
                   title="{{ $tab['name'] }}" class="gtab gtab-{{ $index }}" data-test="game-tab-{{ $tab['slug'] }}">
                    <x-game-cover :game="$tab['slug']" size="thumb" class="w-10 rounded-xs" />
                    <span class="gtab-full">{{ $tab['name'] }}</span><span class="gtab-short" aria-hidden="true">{{ $tab['short'] }}</span>
                </a>
            @endforeach
            <button type="button" class="gtab gtab-hub" aria-controls="game-hub" x-bind:aria-expanded="hub.toString()" aria-expanded="false" aria-haspopup="dialog"
                    x-on:click="toggleHub($el)" aria-label="{{ $allGames }}" data-test="games-menu">
                <x-icon name="grid" :size="18" />
                {{-- "All 4" below 100rem, where Tournaments needs the room: the button keeps "All 4 games" as its name. --}}
                <span aria-hidden="true" class="max-[100rem]:hidden">{{ $allGames }}</span><span aria-hidden="true" class="min-[100rem]:hidden">{{ __('All :count', ['count' => count($games)]) }}</span>
                <x-icon name="chevron-down" :size="16" class="gtab-chevron" />
            </button>
            <span class="grow"></span>
            {{-- Tournaments, cross-game like Clans and Season, with how many are open for sign-up now. --}}
            <a href="{{ $tournaments['href'] }}" @if ($section === 'tournaments') aria-current="page" @endif class="nav-link self-center" data-test="nav-tournaments">
                {{ $tournaments['label'] }}
                @if ($tournaments['open'] > 0)
                    <span class="nav-count" data-test="tournaments-open"><span class="sr-only">, </span>{{ $tournaments['open'] }}<span class="sr-only"> {{ trans_choice('open for sign-up|open for sign-up', $tournaments['open']) }}</span></span>
                @endif
            </a>
            @foreach ($community as $link)
                <a href="{{ $link['href'] }}" @if ($section === $link['key']) aria-current="page" @endif class="nav-link self-center" data-test="nav-{{ $link['key'] }}">
                    {{ $link['label'] }}
                    @if ($link['key'] === 'mining' && $seasonTag)
                        {{-- Below 80rem the tag gives its room to Tournaments; the Season page says the same. --}}
                        <span class="nav-tag max-[80rem]:hidden" data-test="season-tag">{{ $seasonTag }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- The site search (P16, SearchController): Enter opens the results, a match number the match. "/" focuses it. --}}
        <form method="GET" action="{{ route('search') }}" role="search" class="hidden w-40 shrink-0 lg:block xl:w-44" data-test="site-search-form">
            <label for="site-search" class="sr-only">{{ __('Search') }}</label>
            <span class="relative block">
                <x-icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                <input id="site-search" name="q" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search') }}"
                       aria-keyshortcuts="/" class="h-11 w-full rounded-md border border-line bg-ground pr-9 pl-9 text-[13px] text-ink placeholder:text-ink-3">
                <kbd aria-hidden="true" class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded-xs border border-line bg-well px-1.5 text-[11px] leading-5 text-ink-2">/</kbd>
            </span>
        </form>

        <button type="button" class="flex h-11 shrink-0 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-ink-2 hover:text-ink lg:hidden"
                aria-controls="mobile-search" x-bind:aria-expanded="search.toString()" aria-expanded="false"
                x-on:click="search = ! search; closeHub(false); search && $nextTick(() => $refs.mobileSearch.focus())">
            <x-icon name="search" :size="20" />
            <span>{{ __('Search') }}</span>
        </button>

        @if ($user)
            @if ($admin)
                <a href="{{ $admin['href'] }}" @if ($section === 'admin') aria-current="page" @endif class="nav-link hidden lg:flex" data-test="account-admin">
                    <x-icon name="shield-check" :size="18" class="text-ink-3" />
                    {{ __('Admin') }}
                    @if ($admin['count'] > 0)
                        <span class="nav-count" data-test="admin-count"><span class="sr-only">, </span>{{ $admin['count'] }}<span class="sr-only"> {{ trans_choice('open case|open cases', $admin['count']) }}</span></span>
                    @endif
                </a>
            @endif

            <livewire:notification-bell />

            <flux:dropdown position="bottom" align="end" class="hidden lg:block">
                {{-- The chip shows the avatar and "You"; the full name is in the tooltip and at the top of the menu. --}}
                <button type="button" title="{{ $user->displayName() }} · {{ $user->shortNpub() }}" class="flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well pr-2 pl-1.5 text-[13px] text-ink" data-test="account-chip">
                    <x-avatar :user="$user" :size="28" class="shrink-0" />
                    <span>{{ __('You') }}</span><span class="sr-only" data-test="account-chip-name">, {{ $user->displayName() }}</span>
                    <x-icon name="chevron-down" :size="16" class="text-ink-3" />
                </button>

                <flux:menu class="max-w-72">
                    <div class="flex items-center gap-2.5 px-2 pt-1.5 pb-2" data-test="account-menu-name">
                        <x-avatar :user="$user" :size="32" class="shrink-0" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="text-sm font-bold break-words text-ink">{{ $user->displayName() }}</span>
                            <span class="text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                        </span>
                    </div>
                    <flux:menu.separator />
                    @foreach ($account as $link)
                        <flux:menu.item :href="$link['href']" :data-test="$link['test']"><x-icon :name="$link['icon']" :size="16" class="me-2 text-ink-3" />{{ $link['label'] }}</flux:menu.item>
                    @endforeach
                    <flux:menu.separator />
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()">
                        @csrf
                        <flux:menu.item as="button" type="submit" class="w-full"><x-icon name="logout" :size="16" class="me-2 text-ink-3" />{{ __('Log out') }}</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        @else
            <a href="{{ route('login') }}" @if ($onLogin) aria-current="page" @endif
               class="btn-s hidden h-11 shrink-0 items-center rounded-md border border-edge px-4 text-[13px] font-bold text-ink hover:text-ink lg:flex">{{ __('Log in') }}</a>
            {{-- "Start playing": log in, then the chess lobby (the login route remembers `then=play`). --}}
            <a href="{{ route('login', ['then' => 'play']) }}" class="btn-p hidden h-11 shrink-0 items-center rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc hover:text-on-btc lg:flex" data-test="start-playing">{{ __('Start playing') }}</a>
        @endif


        @guest
            <a href="{{ route('login') }}" @if ($onLogin) aria-current="page" @endif
               class="btn-s flex h-11 shrink-0 items-center rounded-md border border-edge px-3 text-[13px] font-bold text-ink hover:text-ink lg:hidden" data-test="mobile-login">{{ __('Log in') }}</a>
        @endguest
    </div>

    {{-- Row 2 (from lg): the context bar of the active game. Icons and full labels from 90rem; short labels below (German ran 69 px past 1280 with full labels). --}}
    <nav class="ctx hidden h-12 items-center gap-1 border-b border-hairline px-6 lg:flex xl:px-8" style="--game: {{ $active['colour'] }}" aria-label="{{ $active['name'] }}" data-test="context-bar" data-game="{{ $active['slug'] }}">
        <span class="ctx-name">{{ $active['name'] }}</span>
        @foreach ($active['actions'] as $link)
            <a href="{{ $link['href'] }}" @if ($link['href'] === $current) aria-current="page" @endif class="ctx-link" data-test="ctx-{{ $link['key'] }}">
                <x-icon :name="$link['icon']" :size="16" class="max-[90rem]:hidden" />
                <span class="min-[90rem]:hidden">{{ $link['short'] }}</span><span class="max-[90rem]:hidden">{{ $link['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div id="mobile-search" class="border-b border-hairline px-4 py-3 lg:hidden" x-show="search" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <form method="GET" action="{{ route('search') }}" role="search">
            <label for="site-search-mobile" class="sr-only">{{ __('Search') }}</label>
            <input id="site-search-mobile" name="q" x-ref="mobileSearch" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search players, clans or match #') }}"
                   class="h-11 w-full rounded-md border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
        </form>
    </div>

    {{-- Guests: "New here?" sits inside the header, so the page-top gap under the header stays the same on every page. --}}
    @guest
        <x-shell.first-steps />
    @endguest

    <x-shell.game-hub :played="$played" :unplayed="$unplayed" :count="count($games)" />
</header>

<x-shell.mobile-nav :active="$active" :games="$games" :community="$community" :tournaments="$tournaments":season-tag="$seasonTag" :admin="$admin" :account="$account" :section="$section" :user="$user" />
